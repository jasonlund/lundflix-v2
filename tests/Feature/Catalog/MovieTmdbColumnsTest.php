<?php

declare(strict_types=1);

use App\Domains\Catalog\Models\Movie;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;

it('persists _tmdb_* values and tmdb_synced_at', function (): void {
    // Arrange
    $movie = Movie::factory()->withTmdb()->make([
        '_tmdb_id' => 550,
        '_tmdb_title' => 'Fight Club',
    ]);

    // Act
    $movie->save();

    // Assert
    $this->assertDatabaseHas('movies', [
        'id' => $movie->id,
        '_tmdb_id' => 550,
        '_tmdb_title' => 'Fight Club',
    ]);
});

it('casts scalar _tmdb_* attributes when fetched fresh from the database', function (): void {
    // Arrange
    $movie = Movie::factory()->withTmdb()->create([
        '_tmdb_id' => 550,
        '_tmdb_runtime' => 139,
        '_tmdb_release_date' => '1999-10-15',
        '_tmdb_video' => false,
        'tmdb_synced_at' => now(),
    ]);

    // Act
    $fresh = Movie::query()->findOrFail($movie->id);

    // Assert
    expect($fresh->_tmdb_id)->toBeInt()
        ->and($fresh->_tmdb_runtime)->toBeInt()
        ->and($fresh->_tmdb_release_date)->toBeInstanceOf(Carbon::class)
        ->and($fresh->_tmdb_video)->toBeBool()
        ->and($fresh->tmdb_synced_at)->toBeInstanceOf(Carbon::class);
});

it('casts json _tmdb_* columns to arrays when fetched fresh', function (): void {
    // Arrange
    $movie = Movie::factory()->withTmdb()->create([
        '_tmdb_genres' => [['id' => 18, 'name' => 'Drama']],
        '_tmdb_belongs_to_collection' => ['id' => 10, 'name' => 'Example Collection'],
        '_tmdb_release_dates' => [['iso_3166_1' => 'US', 'release_dates' => [['certification' => 'R']]]],
    ]);

    // Act
    $fresh = Movie::query()->findOrFail($movie->id);

    // Assert
    expect($fresh->_tmdb_genres)->toBeArray()
        ->and($fresh->_tmdb_genres[0]['name'])->toBe('Drama')
        ->and($fresh->_tmdb_belongs_to_collection)->toBeArray()
        ->and($fresh->_tmdb_belongs_to_collection['name'])->toBe('Example Collection')
        ->and($fresh->_tmdb_release_dates)->toBeArray()
        ->and($fresh->_tmdb_release_dates[0]['iso_3166_1'])->toBe('US');
});

it('creates a movie with a null imdb_id', function (): void {
    // Arrange / Act
    $movie = Movie::factory()->create([
        'imdb_id' => null,
        '_tmdb_id' => 12345,
    ]);

    // Assert
    $this->assertDatabaseHas('movies', [
        'id' => $movie->id,
        'imdb_id' => null,
    ]);
});

it('rejects a duplicate non-null _tmdb_id', function (): void {
    // Arrange
    Movie::factory()->create(['_tmdb_id' => 999]);

    // Act / Assert
    expect(fn () => Movie::factory()->create(['_tmdb_id' => 999]))
        ->toThrow(QueryException::class);
});
