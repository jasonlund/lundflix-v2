<?php

declare(strict_types=1);

use App\Domains\Catalog\Models\Movie;
use Illuminate\Support\Facades\Schema;

it('has an _imdb_id column but no imdb_id column', function (): void {
    // Arrange & Act
    $hasPrefixed = Schema::hasColumn('movies', '_imdb_id');
    $hasUnprefixed = Schema::hasColumn('movies', 'imdb_id');

    // Assert
    expect($hasPrefixed)->toBeTrue()
        ->and($hasUnprefixed)->toBeFalse();
});

it('persists a movie row to the database', function (): void {
    // Arrange
    $movie = Movie::factory()->make();

    // Act
    $movie->save();

    // Assert
    $this->assertDatabaseHas('movies', [
        'id' => $movie->id,
        '_imdb_id' => $movie->_imdb_id,
    ]);
});

it('allows two movies to share the same _imdb_id', function (): void {
    // Arrange
    Movie::factory()->create(['_imdb_id' => 'tt0000001']);

    // Act
    Movie::factory()->create(['_imdb_id' => 'tt0000001']);

    // Assert
    expect(Movie::query()->where('_imdb_id', 'tt0000001')->count())->toBe(2);
});

it('round-trips the IMDb rating attributes under their verbatim camelCase names', function (): void {
    // Arrange
    $movie = Movie::factory()->create([
        '_imdb_numVotes' => 1_800_000,
        '_imdb_averageRating' => 8.7,
    ]);

    // Act
    $fresh = Movie::query()->findOrFail($movie->id);

    // Assert
    expect($fresh->_imdb_numVotes)->toBeInt()
        ->and($fresh->_imdb_numVotes)->toBe(1_800_000)
        ->and($fresh->_imdb_averageRating)->toBeFloat()
        ->and($fresh->_imdb_averageRating)->toBe(8.7);
});

it('has the camelCase IMDb rating columns and no snake_case ones on movies', function (): void {
    // Arrange & Act
    $hasCamel = Schema::hasColumn('movies', '_imdb_numVotes')
        && Schema::hasColumn('movies', '_imdb_averageRating');
    $hasSnake = Schema::hasColumn('movies', '_imdb_num_votes')
        || Schema::hasColumn('movies', '_imdb_average_rating');

    // Assert
    expect($hasCamel)->toBeTrue()
        ->and($hasSnake)->toBeFalse();
});

it('has all eight IMDb basics and akas columns on movies', function (): void {
    // Arrange & Act
    $missing = array_values(array_filter([
        '_imdb_titleType',
        '_imdb_primaryTitle',
        '_imdb_originalTitle',
        '_imdb_startYear',
        '_imdb_endYear',
        '_imdb_runtimeMinutes',
        '_imdb_genres',
        '_imdb_akas',
    ], fn (string $column): bool => ! Schema::hasColumn('movies', $column)));

    // Assert
    expect($missing)->toBe([]);
});

it('has no _imdb_isAdult column on movies', function (): void {
    // Arrange & Act
    $hasIsAdult = Schema::hasColumn('movies', '_imdb_isAdult');

    // Assert
    expect($hasIsAdult)->toBeFalse();
});

it('round-trips the IMDb basics numerics as ints and the genres and akas as arrays', function (): void {
    // Arrange
    $movie = Movie::factory()->create([
        '_imdb_titleType' => 'movie',
        '_imdb_primaryTitle' => 'Heat',
        '_imdb_originalTitle' => 'Heat',
        '_imdb_startYear' => 1995,
        '_imdb_endYear' => 1996,
        '_imdb_runtimeMinutes' => 170,
        '_imdb_genres' => ['Action', 'Crime', 'Drama'],
        '_imdb_akas' => ['Heat', 'Fuego contra fuego'],
    ]);

    // Act
    $fresh = Movie::query()->findOrFail($movie->id);

    // Assert
    expect($fresh->_imdb_titleType)->toBe('movie')
        ->and($fresh->_imdb_primaryTitle)->toBe('Heat')
        ->and($fresh->_imdb_originalTitle)->toBe('Heat')
        ->and($fresh->_imdb_startYear)->toBe(1995)
        ->and($fresh->_imdb_endYear)->toBe(1996)
        ->and($fresh->_imdb_runtimeMinutes)->toBe(170)
        ->and($fresh->_imdb_genres)->toBe(['Action', 'Crime', 'Drama'])
        ->and($fresh->_imdb_akas)->toBe(['Heat', 'Fuego contra fuego']);
});
