<?php

declare(strict_types=1);

use App\Domains\Download\Enums\Category;
use App\Domains\Download\Enums\Codec;
use App\Domains\Download\Enums\Quality;
use App\Domains\Download\Enums\ReleaseTag;
use App\Domains\Download\Enums\Source;
use App\Domains\Download\Models\Download;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('creates a persistable Download via its factory', function (): void {
    // Arrange
    // no state to set up — the factory supplies a full row

    // Act
    $download = Download::factory()->create();

    // Assert
    $this->assertDatabaseHas('downloads', ['id' => $download->id]);
    expect($download->fresh()->_provider_id)->not->toBeNull();
});

it('reads _provider_category back as a Category enum', function (): void {
    // Arrange
    $download = Download::factory()->create(['_provider_category' => '72']);

    // Act
    $fresh = $download->fresh();

    // Assert
    expect($fresh->_provider_category)->toBe(Category::Movies);
});

it('applies name-derived enum and bool casts on read', function (): void {
    // Arrange
    $download = Download::factory()->create();

    // Act
    $fresh = $download->fresh();

    // Assert
    expect($fresh->quality)->toBeInstanceOf(Quality::class);
    expect($fresh->codec)->toBeInstanceOf(Codec::class);
    expect($fresh->source)->toBeInstanceOf(Source::class);
    expect($fresh->release_tag)->toBeInstanceOf(ReleaseTag::class);
    expect($fresh->is_rar)->toBeBool();
});

it('applies json and datetime casts on read', function (): void {
    // Arrange
    $download = Download::factory()->create();

    // Act
    $fresh = $download->fresh();

    // Assert
    expect($fresh->_provider_files)->toBeArray();
    expect($fresh->_provider_files[0])->toMatchArray(['name' => 'file.bin', 'size_bytes' => 1_000_000]);
    expect($fresh->_provider_description)->toBeArray()->toHaveKeys(['text', 'screenshots']);
    expect($fresh->_provider_description['screenshots'])->toBe(['https://example.test/a.jpg', 'https://example.test/b.jpg']);
    expect($fresh->_provider_published_at)->toBeInstanceOf(CarbonImmutable::class);
    expect($fresh->index_synced_at)->toBeInstanceOf(CarbonImmutable::class);
    expect($fresh->rss_synced_at)->toBeInstanceOf(CarbonImmutable::class);
    expect($fresh->detail_synced_at)->toBeInstanceOf(CarbonImmutable::class);
    expect($fresh->filelist_synced_at)->toBeInstanceOf(CarbonImmutable::class);
});

it('rejects a duplicate _provider_id', function (): void {
    // Arrange
    Download::factory()->create(['_provider_id' => 12345]);

    // Act & Assert
    expect(fn () => Download::factory()->create(['_provider_id' => 12345]))->toThrow(QueryException::class);
});

it('persists the imdb-match columns as null for an index-sourced row', function (): void {
    // Arrange
    // an index- or RSS-sourced row created before imdb matching

    // Act
    $download = Download::factory()->create([
        '_imdb_id' => null,
        '_tmdb_id' => null,
        '_provider_files' => null,
        '_provider_description' => null,
    ]);

    // Assert
    $fresh = $download->fresh();
    expect($fresh->_imdb_id)->toBeNull();
    expect($fresh->_tmdb_id)->toBeNull();
    expect($fresh->_provider_files)->toBeNull();
    expect($fresh->_provider_description)->toBeNull();
});
