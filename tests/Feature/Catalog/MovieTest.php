<?php

use App\Domains\Catalog\Enums\Genre;
use App\Domains\Catalog\Models\Movie;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;

it('persists a movie row to the database', function () {
    // Arrange
    $movie = Movie::factory()->make();

    // Act
    $movie->save();

    // Assert
    $this->assertDatabaseHas('movies', [
        'id' => $movie->id,
        'imdb_id' => $movie->imdb_id,
        'title' => $movie->title,
    ]);
});

it('rejects a duplicate imdb_id', function () {
    // Arrange
    Movie::factory()->create(['imdb_id' => 'tt1234567']);

    // Act / Assert
    expect(fn () => Movie::factory()->create(['imdb_id' => 'tt1234567']))
        ->toThrow(QueryException::class);
});

it('casts typed attributes when fetched fresh from the database', function () {
    // Arrange
    $movie = Movie::factory()->create([
        'year' => 1999,
        'runtime' => 136,
        'num_votes' => 1_800_000,
        'average_rating' => 8.7,
        'genres' => [Genre::Action, Genre::Drama],
    ]);

    // Act
    $fresh = Movie::query()->findOrFail($movie->id);

    // Assert
    expect($fresh->year)->toBeInt()
        ->and($fresh->runtime)->toBeInt()
        ->and($fresh->num_votes)->toBeInt()
        ->and($fresh->average_rating)->toBeFloat()
        ->and($fresh->genres)->toBeInstanceOf(Collection::class)
        ->and($fresh->genres[0])->toBeInstanceOf(Genre::class);
});

it('exposes only the searchable keys with matching values', function () {
    // Arrange
    $movie = Movie::factory()->create();

    // Act
    $searchable = $movie->toSearchableArray();

    // Assert
    expect(array_keys($searchable))->toEqualCanonicalizing(['id', 'imdb_id', 'title', 'year', 'num_votes'])
        ->and($searchable['id'])->toBe($movie->id)
        ->and($searchable['imdb_id'])->toBe($movie->imdb_id)
        ->and($searchable['title'])->toBe($movie->title)
        ->and($searchable['year'])->toBe($movie->year)
        ->and($searchable['num_votes'])->toBe($movie->num_votes);
});
