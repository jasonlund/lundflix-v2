<?php

declare(strict_types=1);

use App\Domains\Catalog\Models\Media;

it('persists _tvdb_* values to the database', function (): void {
    // Arrange
    $media = Media::factory()->withTvdb()->make([
        '_tvdb_image' => 'https://artworks.thetvdb.com/banners/posters/81189-10.jpg',
        '_tvdb_type' => 2,
        '_tvdb_language' => 'eng',
    ]);

    // Act
    $media->save();

    // Assert
    $this->assertDatabaseHas('media', [
        'id' => $media->id,
        '_tvdb_image' => 'https://artworks.thetvdb.com/banners/posters/81189-10.jpg',
        '_tvdb_type' => 2,
        '_tvdb_language' => 'eng',
    ]);
});

it('casts typed _tvdb_* attributes when fetched fresh from the database', function (): void {
    // Arrange
    $media = Media::factory()->withTvdb()->create([
        '_tvdb_width' => 680,
        '_tvdb_height' => 1000,
        '_tvdb_type' => 2,
        '_tvdb_score' => 100141,
    ]);

    // Act
    $fresh = Media::query()->findOrFail($media->id);

    // Assert
    expect($fresh->_tvdb_width)->toBeInt()
        ->and($fresh->_tvdb_height)->toBeInt()
        ->and($fresh->_tvdb_type)->toBeInt()
        ->and($fresh->_tvdb_score)->toBeFloat();
});
