<?php

declare(strict_types=1);

use App\Domains\Catalog\Actions\UpsertTmdbImages;
use App\Domains\Catalog\Enums\ArtworkType;
use App\Domains\Catalog\Models\Media;
use App\Domains\Catalog\Models\Movie;
use App\Domains\Catalog\Models\Show;

it('maps each image to a typed active media row with raw attrs', function (): void {
    // Arrange
    $movie = Movie::factory()->create();
    $decoded = json_decode(fixtureBytes('Catalog/tmdb/movie.json'), true);
    $images = $decoded['images'];

    // Act
    $count = (new UpsertTmdbImages)->handle($movie, $images);

    // Assert
    expect($movie->media()->where('type', ArtworkType::Poster)->count())->toBe(126)
        ->and($movie->media()->where('type', ArtworkType::Backdrop)->count())->toBe(87)
        ->and($movie->media()->where('type', ArtworkType::Logo)->count())->toBe(16)
        ->and($count)->toBe(229);

    $this->assertDatabaseCount('media', 229);
    $this->assertDatabaseHas('media', [
        'mediable_type' => $movie->getMorphClass(),
        'mediable_id' => $movie->id,
        'type' => 'poster',
        'is_active' => true,
        '_tmdb_file_path' => '/aOIuZAjPaRIE6CMzbazvcHuHXDc.jpg',
        '_tmdb_iso_639_1' => 'en',
        '_tmdb_iso_3166_1' => 'US',
        '_tmdb_vote_average' => 6.2,
        '_tmdb_vote_count' => 35,
        '_tmdb_width' => 2000,
        '_tmdb_height' => 3000,
        '_tmdb_aspect_ratio' => 0.667,
    ]);
    expect($movie->media()->where('is_active', true)->count())->toBe(229);
});

it('empty images block is a no-op', function (): void {
    // Arrange
    $movie = Movie::factory()->create();

    // Act
    $count = (new UpsertTmdbImages)->handle($movie, []);

    // Assert
    $this->assertDatabaseCount('media', 0);
    expect($count)->toBe(0);
});

it('deactivates stale managed-type art no longer in the payload', function (): void {
    // Arrange
    $movie = Movie::factory()->create();
    Media::factory()->create([
        'mediable_id' => $movie->id,
        'mediable_type' => $movie->getMorphClass(),
        'type' => ArtworkType::Poster,
        'is_active' => true,
        '_tmdb_file_path' => '/STALE-not-in-payload.jpg',
    ]);
    $images = json_decode(fixtureBytes('Catalog/tmdb/movie.json'), true)['images'];

    // Act
    (new UpsertTmdbImages)->handle($movie, $images);

    // Assert
    $this->assertDatabaseHas('media', [
        'mediable_id' => $movie->id,
        '_tmdb_file_path' => '/STALE-not-in-payload.jpg',
        'is_active' => false,
    ]);
    $this->assertDatabaseHas('media', [
        'mediable_id' => $movie->id,
        '_tmdb_file_path' => '/aOIuZAjPaRIE6CMzbazvcHuHXDc.jpg',
        'is_active' => true,
    ]);
});

it('is idempotent on re-run — no duplicate rows and a stable active set', function (): void {
    // Arrange
    $movie = Movie::factory()->create();
    $images = json_decode(fixtureBytes('Catalog/tmdb/movie.json'), true)['images'];

    // Act
    $action = new UpsertTmdbImages;
    $action->handle($movie, $images);
    $action->handle($movie, $images);

    // Assert
    $this->assertDatabaseCount('media', 229);
    expect(Media::where('is_active', true)->count())->toBe(229);
});

it('skips images missing a file_path instead of creating a null-path row', function (): void {
    // Arrange
    $movie = Movie::factory()->create();
    $images = [
        'posters' => [
            ['file_path' => '/valid-poster.jpg', 'vote_average' => 5.0],
            ['vote_average' => 7.0],
        ],
    ];

    // Act
    $count = (new UpsertTmdbImages)->handle($movie, $images);

    // Assert
    $this->assertDatabaseCount('media', 1);
    $this->assertDatabaseHas('media', [
        'mediable_id' => $movie->id,
        '_tmdb_file_path' => '/valid-poster.jpg',
        'is_active' => true,
    ]);
    $this->assertDatabaseMissing('media', ['_tmdb_file_path' => null]);
    expect($count)->toBe(1);
});

it('reactivates a previously-inactive file_path that reappears in the payload', function (): void {
    // Arrange
    $movie = Movie::factory()->create();
    Media::factory()->create([
        'mediable_id' => $movie->id,
        'mediable_type' => $movie->getMorphClass(),
        'type' => ArtworkType::Poster,
        'is_active' => false,
        '_tmdb_file_path' => '/aOIuZAjPaRIE6CMzbazvcHuHXDc.jpg',
    ]);
    $images = json_decode(fixtureBytes('Catalog/tmdb/movie.json'), true)['images'];

    // Act
    (new UpsertTmdbImages)->handle($movie, $images);

    // Assert
    $this->assertDatabaseHas('media', [
        'mediable_id' => $movie->id,
        '_tmdb_file_path' => '/aOIuZAjPaRIE6CMzbazvcHuHXDc.jpg',
        'is_active' => true,
    ]);
    expect($movie->media()->where('_tmdb_file_path', '/aOIuZAjPaRIE6CMzbazvcHuHXDc.jpg')->count())->toBe(1);
});

it('persists tmdb images against a show into active media rows', function (): void {
    // Arrange
    $show = Show::factory()->create();
    $images = json_decode(fixtureBytes('Catalog/tmdb/tv.json'), true)['images'];

    // Act
    $count = (new UpsertTmdbImages)->handle($show, $images);

    // Assert
    expect($show->media()->where('type', ArtworkType::Poster)->count())->toBe(207)
        ->and($show->media()->where('type', ArtworkType::Backdrop)->count())->toBe(423)
        ->and($show->media()->where('type', ArtworkType::Logo)->count())->toBe(13)
        ->and($count)->toBe(643);

    $this->assertDatabaseHas('media', [
        'mediable_type' => $show->getMorphClass(),
        'mediable_id' => $show->id,
        'type' => 'poster',
        'is_active' => true,
        '_tmdb_file_path' => '/1XS1oqL89opfnbLl8WnZY1O1uJx.jpg',
    ]);
});
