<?php

declare(strict_types=1);

use App\Domains\Catalog\Models\Media;
use App\Domains\Catalog\Models\Movie;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

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

it('stores the morph-map alias in mediable_type rather than the FQCN', function (): void {
    // Arrange
    $movie = Movie::factory()->create();

    // Act
    $media = Media::factory()->for($movie, 'mediable')->create();

    // Assert
    expect(DB::table('media')->where('id', $media->id)->value('mediable_type'))->toBe('movie')
        ->and($media->fresh()->mediable)->toBeInstanceOf(Movie::class);
});

it('rejects duplicate artwork for the same parent and file path', function (): void {
    // Arrange
    $movie = Movie::factory()->create();
    Media::factory()->for($movie, 'mediable')->create(['_tmdb_file_path' => '/dup.jpg']);

    // Act / Assert
    expect(fn () => Media::factory()->for($movie, 'mediable')->create(['_tmdb_file_path' => '/dup.jpg']))
        ->toThrow(QueryException::class);
});
