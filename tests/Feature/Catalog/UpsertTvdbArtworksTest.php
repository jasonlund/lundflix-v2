<?php

declare(strict_types=1);

use App\Domains\Catalog\Actions\UpsertTvdbArtworks;
use App\Domains\Catalog\Enums\ArtworkType;
use App\Domains\Catalog\Models\Media;
use App\Domains\Catalog\Models\Show;

// Fixture: tests/Fixtures/Catalog/tvdb/series_extended.json — a byte-exact real
// TheTVDB /series/{id}/extended capture. ['data']['artworks'] holds a flat list
// of 343 entries with numeric `type` codes. Mapped via ArtworkType::fromTvdb:
// 2→Poster (45), 3→Backdrop (55), 23→Logo (9) = 109 active rows; the remaining
// codes 1 (23), 5 (6), 6 (34), 7 (154), 22 (17) = 234 are unmapped and skipped.

it('persists tvdb artworks as typed active media with raw _tvdb_ attrs', function (): void {
    // Arrange
    $show = Show::factory()->create();
    $artworks = json_decode(fixtureBytes('Catalog/tvdb/series_extended.json'), true)['data']['artworks'];

    // Act
    $count = (new UpsertTvdbArtworks)->handle($show, $artworks);

    // Assert
    expect($show->media()->where('type', ArtworkType::Poster)->count())->toBe(45)
        ->and($show->media()->where('type', ArtworkType::Backdrop)->count())->toBe(55)
        ->and($show->media()->where('type', ArtworkType::Logo)->count())->toBe(9)
        ->and($count)->toBe(109);

    $this->assertDatabaseHas('media', [
        'mediable_type' => $show->getMorphClass(),
        'mediable_id' => $show->id,
        'type' => 'poster',
        'is_active' => true,
        '_tvdb_image' => 'https://artworks.thetvdb.com/banners/posters/81189-10.jpg',
        '_tvdb_language' => 'eng',
        '_tvdb_width' => 680,
        '_tvdb_height' => 1000,
    ]);
    expect($show->media()->where('is_active', true)->count())->toBe(109);
});

it('persisted media url() returns the absolute image verbatim', function (): void {
    // Arrange
    $show = Show::factory()->create();
    $artworks = json_decode(fixtureBytes('Catalog/tvdb/series_extended.json'), true)['data']['artworks'];

    // Act
    (new UpsertTvdbArtworks)->handle($show, $artworks);

    // Assert
    $media = $show->media()
        ->where('_tvdb_image', 'https://artworks.thetvdb.com/banners/posters/81189-10.jpg')
        ->firstOrFail();
    expect($media->url())->toBe('https://artworks.thetvdb.com/banners/posters/81189-10.jpg');
});

it('skips artworks whose numeric type has no ArtworkType mapping', function (): void {
    // Arrange
    $show = Show::factory()->create();
    $artworks = json_decode(fixtureBytes('Catalog/tvdb/series_extended.json'), true)['data']['artworks'];

    // Act
    (new UpsertTvdbArtworks)->handle($show, $artworks);

    // Assert
    $this->assertDatabaseCount('media', 109);
});

it('deactivates stale tvdb art and reactivates returning art on re-run', function (): void {
    // Arrange
    $show = Show::factory()->create();
    Media::factory()->withTvdb()->create([
        'mediable_id' => $show->id,
        'mediable_type' => $show->getMorphClass(),
        'type' => ArtworkType::Poster,
        'is_active' => true,
        '_tvdb_image' => 'https://artworks.thetvdb.com/STALE-not-in-payload.jpg',
    ]);
    Media::factory()->withTvdb()->create([
        'mediable_id' => $show->id,
        'mediable_type' => $show->getMorphClass(),
        'type' => ArtworkType::Poster,
        'is_active' => false,
        '_tvdb_image' => 'https://artworks.thetvdb.com/banners/posters/81189-10.jpg',
    ]);
    $artworks = json_decode(fixtureBytes('Catalog/tvdb/series_extended.json'), true)['data']['artworks'];

    // Act
    (new UpsertTvdbArtworks)->handle($show, $artworks);

    // Assert
    $this->assertDatabaseHas('media', [
        'mediable_id' => $show->id,
        '_tvdb_image' => 'https://artworks.thetvdb.com/STALE-not-in-payload.jpg',
        'is_active' => false,
    ]);
    $this->assertDatabaseHas('media', [
        'mediable_id' => $show->id,
        '_tvdb_image' => 'https://artworks.thetvdb.com/banners/posters/81189-10.jpg',
        'is_active' => true,
    ]);
    expect($show->media()->where('_tvdb_image', 'https://artworks.thetvdb.com/banners/posters/81189-10.jpg')->count())->toBe(1);
});

it('is idempotent on re-run — no duplicate rows and a stable active set', function (): void {
    // Arrange
    $show = Show::factory()->create();
    $artworks = json_decode(fixtureBytes('Catalog/tvdb/series_extended.json'), true)['data']['artworks'];

    // Act
    $action = new UpsertTvdbArtworks;
    $action->handle($show, $artworks);
    $action->handle($show, $artworks);

    // Assert
    $this->assertDatabaseCount('media', 109);
    expect(Media::where('is_active', true)->count())->toBe(109);
});
