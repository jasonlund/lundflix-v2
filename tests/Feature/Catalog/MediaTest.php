<?php

declare(strict_types=1);

use App\Domains\Catalog\Enums\ArtworkType;
use App\Domains\Catalog\Models\Media;

it('persists a media row to the database', function (): void {
    // Arrange
    $media = Media::factory()->make([
        '_tmdb_file_path' => '/abc.jpg',
        'type' => ArtworkType::Poster,
        '_tmdb_iso_639_1' => 'en',
        '_tmdb_width' => 500,
    ]);

    // Act
    $media->save();

    // Assert
    $this->assertDatabaseHas('media', [
        'id' => $media->id,
        '_tmdb_file_path' => '/abc.jpg',
        'type' => 'poster',
    ]);
});

it('casts typed attributes when fetched fresh from the database', function (): void {
    // Arrange
    $media = Media::factory()->create([
        'type' => ArtworkType::Poster,
        '_tmdb_vote_average' => 5.4,
        '_tmdb_aspect_ratio' => 0.667,
        '_tmdb_width' => 500,
        '_tmdb_height' => 750,
        '_tmdb_vote_count' => 12,
        'is_active' => true,
    ]);

    // Act
    $fresh = Media::query()->findOrFail($media->id);

    // Assert
    expect($fresh->type)->toBeInstanceOf(ArtworkType::class)
        ->and($fresh->type)->toBe(ArtworkType::Poster)
        ->and($fresh->_tmdb_vote_average)->toBeFloat()
        ->and($fresh->_tmdb_aspect_ratio)->toBeFloat()
        ->and($fresh->_tmdb_width)->toBeInt()
        ->and($fresh->_tmdb_height)->toBeInt()
        ->and($fresh->_tmdb_vote_count)->toBeInt()
        ->and($fresh->is_active)->toBeBool();
});

it('builds the default-size CDN URL from the artwork type and file path', function (): void {
    // Arrange
    $media = Media::factory()->create([
        'type' => ArtworkType::Poster,
        '_tmdb_file_path' => '/abc.jpg',
    ]);

    // Act
    $url = $media->url();

    // Assert
    expect($url)->toBe('https://image.tmdb.org/t/p/w500/abc.jpg');
});

it('returns null from url() when the file path is null', function (): void {
    // Arrange
    $media = Media::factory()->create([
        'type' => ArtworkType::Poster,
        '_tmdb_file_path' => null,
    ]);

    // Act
    $url = $media->url();

    // Assert
    expect($url)->toBeNull();
});

it('overrides the size segment when a size is passed to url()', function (): void {
    // Arrange
    $media = Media::factory()->create([
        'type' => ArtworkType::Poster,
        '_tmdb_file_path' => '/abc.jpg',
    ]);

    // Act
    $url = $media->url('original');

    // Assert
    expect($url)->toBe('https://image.tmdb.org/t/p/original/abc.jpg');
});

it('returns the tvdb absolute image verbatim from url()', function (): void {
    // Arrange
    $media = Media::factory()->withTvdb()->create([
        '_tvdb_image' => 'https://artworks.thetvdb.com/banners/posters/81189-10.jpg',
    ]);

    // Act
    $url = $media->url();

    // Assert
    expect($url)->toBe('https://artworks.thetvdb.com/banners/posters/81189-10.jpg');
});

it('ignores a passed size for a tvdb row in url()', function (): void {
    // Arrange
    $media = Media::factory()->withTvdb()->create([
        '_tvdb_image' => 'https://artworks.thetvdb.com/banners/posters/81189-10.jpg',
    ]);

    // Act
    $url = $media->url('original');

    // Assert
    expect($url)->toBe('https://artworks.thetvdb.com/banners/posters/81189-10.jpg');
});

it('still builds the tmdb CDN url when tvdb image is null', function (): void {
    // Arrange
    $media = Media::factory()->create([
        'type' => ArtworkType::Poster,
        '_tmdb_file_path' => '/abc.jpg',
        '_tvdb_image' => null,
    ]);

    // Act
    $url = $media->url();

    // Assert
    expect($url)->toBe('https://image.tmdb.org/t/p/w500/abc.jpg');
});

it('returns null from url() when both tvdb image and tmdb file path are null', function (): void {
    // Arrange
    $media = Media::factory()->withTvdb()->create([
        '_tvdb_image' => null,
    ]);

    // Act
    $url = $media->url();

    // Assert
    expect($url)->toBeNull();
});
