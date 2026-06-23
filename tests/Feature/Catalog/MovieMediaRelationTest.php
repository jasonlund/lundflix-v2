<?php

declare(strict_types=1);

use App\Domains\Catalog\Models\Media;
use App\Domains\Catalog\Models\Movie;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;

it('returns the movie media rows via the morphMany relation', function (): void {
    // Arrange
    $movie = Movie::factory()->create();
    Media::factory()->count(2)->for($movie, 'mediable')->create();

    // Act
    $movie->refresh();
    $media = $movie->media;

    // Assert
    expect($media)->toBeInstanceOf(Collection::class)
        ->and($media)->toHaveCount(2)
        ->and($media[0])->toBeInstanceOf(Media::class)
        ->and($media[1])->toBeInstanceOf(Media::class);
});

it('resolves the parent movie via the mediable morphTo relation', function (): void {
    // Arrange
    $movie = Movie::factory()->create();
    $media = Media::factory()->for($movie, 'mediable')->create();

    // Act
    $parent = Media::query()->findOrFail($media->id)->mediable;

    // Assert
    expect($parent)->toBeInstanceOf(Movie::class)
        ->and($parent->is($movie))->toBeTrue();
});

it('rejects duplicate artwork for the same parent and file path', function (): void {
    // Arrange
    $movie = Movie::factory()->create();
    Media::factory()->for($movie, 'mediable')->create(['_tmdb_file_path' => '/dup.jpg']);

    // Act / Assert
    expect(fn () => Media::factory()->for($movie, 'mediable')->create(['_tmdb_file_path' => '/dup.jpg']))
        ->toThrow(QueryException::class);
});
