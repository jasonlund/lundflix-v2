<?php

declare(strict_types=1);

use App\Domains\Catalog\Enums\Genre;
use App\Domains\Catalog\Enums\TitleType;
use App\Domains\Catalog\Models\Movie;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

it('has an _imdb_id column but no imdb_id column', function (): void {
    // Arrange & Act
    $hasPrefixed = Schema::hasColumn('movies', '_imdb_id');
    $hasUnprefixed = Schema::hasColumn('movies', 'imdb_id');

    // Assert
    expect($hasPrefixed)->toBeTrue()
        ->and($hasUnprefixed)->toBeFalse();
});

it('exposes the imdb descriptive columns under the _imdb_ prefix only', function (): void {
    // Arrange & Act
    $hasPrefixed = [
        Schema::hasColumn('movies', '_imdb_primary_title'),
        Schema::hasColumn('movies', '_imdb_title_type'),
        Schema::hasColumn('movies', '_imdb_start_year'),
        Schema::hasColumn('movies', '_imdb_runtime_minutes'),
        Schema::hasColumn('movies', '_imdb_genres'),
    ];
    $hasUnprefixed = [
        Schema::hasColumn('movies', 'title'),
        Schema::hasColumn('movies', 'title_type'),
        Schema::hasColumn('movies', 'year'),
        Schema::hasColumn('movies', 'runtime'),
        Schema::hasColumn('movies', 'genres'),
    ];

    // Assert
    expect($hasPrefixed)->each->toBeTrue()
        ->and($hasUnprefixed)->each->toBeFalse();
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
        '_imdb_primary_title' => $movie->_imdb_primary_title,
    ]);
});

it('rejects a duplicate _imdb_id', function (): void {
    // Arrange
    Movie::factory()->create(['_imdb_id' => 'tt1234567']);

    // Act & Assert
    expect(fn () => Movie::factory()->create(['_imdb_id' => 'tt1234567']))
        ->toThrow(QueryException::class);
});

it('casts typed attributes when fetched fresh from the database', function (): void {
    // Arrange
    $movie = Movie::factory()->create([
        '_imdb_title_type' => TitleType::Movie,
        '_imdb_start_year' => 1999,
        '_imdb_runtime_minutes' => 136,
        '_imdb_num_votes' => 1_800_000,
        '_imdb_average_rating' => 8.7,
        '_imdb_genres' => [Genre::Action, Genre::Drama],
    ]);

    // Act
    $fresh = Movie::query()->findOrFail($movie->id);

    // Assert
    expect($fresh->_imdb_title_type)->toBe(TitleType::Movie)
        ->and($fresh->_imdb_start_year)->toBeInt()
        ->and($fresh->_imdb_runtime_minutes)->toBeInt()
        ->and($fresh->_imdb_num_votes)->toBeInt()
        ->and($fresh->_imdb_average_rating)->toBeFloat()
        ->and($fresh->_imdb_genres)->toBeInstanceOf(Collection::class)
        ->and($fresh->_imdb_genres[0])->toBeInstanceOf(Genre::class);
});

it('exposes only the searchable keys with matching values', function (): void {
    // Arrange
    $movie = Movie::factory()->create();

    // Act
    $searchable = $movie->toSearchableArray();

    // Assert
    expect(array_keys($searchable))->toEqualCanonicalizing(['id', 'imdb_id', 'title', 'year', 'num_votes'])
        ->and($searchable['id'])->toBe($movie->id)
        ->and($searchable['imdb_id'])->toBe($movie->_imdb_id)
        ->and($searchable['title'])->toBe($movie->_imdb_primary_title)
        ->and($searchable['year'])->toBe($movie->_imdb_start_year)
        ->and($searchable['num_votes'])->toBe($movie->_imdb_num_votes);
});
