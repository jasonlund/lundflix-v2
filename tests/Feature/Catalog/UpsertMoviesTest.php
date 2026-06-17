<?php

declare(strict_types=1);

use App\Domains\Catalog\Actions\UpsertMovies;
use App\Domains\Catalog\Enums\Genre;
use App\Domains\Catalog\Enums\TitleType;
use App\Domains\Catalog\Models\Movie;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Cast-row inputs are hand-built plain PHP arrays, mirroring the exact shape
| produced by ImdbDatasetService::rows() (genres already an array of strings,
| startYear/runtimeMinutes already int|null). These are synthetic-but-realistic
| inputs to the action, NOT external-response fixtures.
|--------------------------------------------------------------------------
*/

it('maps cast rows to movie columns and returns the upserted count', function () {
    // Arrange
    $rows = [
        ['tconst' => 'tt0133093', 'titleType' => 'movie', 'primaryTitle' => 'The Matrix', 'originalTitle' => 'The Matrix', 'startYear' => 1999, 'endYear' => null, 'runtimeMinutes' => 136, 'genres' => ['Action', 'Sci-Fi']],
        ['tconst' => 'tt0137523', 'titleType' => 'movie', 'primaryTitle' => 'Fight Club', 'originalTitle' => 'Fight Club', 'startYear' => 1999, 'endYear' => null, 'runtimeMinutes' => 139, 'genres' => ['Drama']],
        ['tconst' => 'tt0110912', 'titleType' => 'movie', 'primaryTitle' => 'Pulp Fiction', 'originalTitle' => 'Pulp Fiction', 'startYear' => 1994, 'endYear' => null, 'runtimeMinutes' => 154, 'genres' => ['Crime', 'Drama']],
    ];

    // Act
    $count = app(UpsertMovies::class)->handle($rows);

    // Assert
    expect($count)->toBe(3);
    $this->assertDatabaseHas('movies', ['imdb_id' => 'tt0133093', 'title' => 'The Matrix', 'year' => 1999, 'runtime' => 136]);
    $this->assertDatabaseHas('movies', ['imdb_id' => 'tt0137523', 'title' => 'Fight Club', 'year' => 1999, 'runtime' => 139]);
    $this->assertDatabaseHas('movies', ['imdb_id' => 'tt0110912', 'title' => 'Pulp Fiction', 'year' => 1994, 'runtime' => 154]);
});

it('drops unknown genre values without throwing', function () {
    // Arrange
    $rows = [
        ['tconst' => 'tt0133093', 'titleType' => 'movie', 'primaryTitle' => 'The Matrix', 'originalTitle' => 'The Matrix', 'startYear' => 1999, 'endYear' => null, 'runtimeMinutes' => 136, 'genres' => ['Action', 'NotAGenre']],
    ];

    // Act
    app(UpsertMovies::class)->handle($rows);

    // Assert
    $movie = Movie::query()->where('imdb_id', 'tt0133093')->firstOrFail();
    expect($movie->genres->all())->toContain(Genre::Action)
        ->and($movie->genres->all())->toEqual([Genre::Action]);
});

it('preserves num_votes and average_rating on re-upsert', function () {
    // Arrange
    $existing = Movie::factory()->create([
        'imdb_id' => 'tt0133093',
        'title' => 'Old Title',
        'year' => 1980,
        'runtime' => 90,
        'genres' => [Genre::Horror],
    ]);
    $originalVotes = $existing->num_votes;
    $originalRating = $existing->average_rating;

    // Act
    app(UpsertMovies::class)->handle([
        ['tconst' => 'tt0133093', 'titleType' => 'movie', 'primaryTitle' => 'The Matrix', 'originalTitle' => 'The Matrix', 'startYear' => 1999, 'endYear' => null, 'runtimeMinutes' => 136, 'genres' => ['Action', 'Sci-Fi']],
    ]);

    // Assert
    $fresh = Movie::query()->where('imdb_id', 'tt0133093')->firstOrFail();
    expect(Movie::query()->count())->toBe(1)
        ->and($fresh->title)->toBe('The Matrix')
        ->and($fresh->year)->toBe(1999)
        ->and($fresh->runtime)->toBe(136)
        ->and($fresh->genres->all())->toEqual([Genre::Action, Genre::SciFi])
        ->and($fresh->num_votes)->toBe($originalVotes)
        ->and($fresh->average_rating)->toBe($originalRating);
});

it('persists genres readable back through the enum-collection cast', function () {
    // Arrange
    $rows = [
        ['tconst' => 'tt0133093', 'titleType' => 'movie', 'primaryTitle' => 'The Matrix', 'originalTitle' => 'The Matrix', 'startYear' => 1999, 'endYear' => null, 'runtimeMinutes' => 136, 'genres' => ['Action', 'Sci-Fi']],
    ];

    // Act
    app(UpsertMovies::class)->handle($rows);

    // Assert
    $movie = Movie::query()->where('imdb_id', 'tt0133093')->firstOrFail();
    expect($movie->genres->all())->toEqual([Genre::Action, Genre::SciFi]);
});

it('stores SQL NULL for a row whose genres field is null, not the string "[]"', function () {
    // Arrange
    $rows = [
        ['tconst' => 'tt0133093', 'titleType' => 'movie', 'primaryTitle' => 'The Matrix', 'originalTitle' => 'The Matrix', 'startYear' => 1999, 'endYear' => null, 'runtimeMinutes' => 136, 'genres' => null],
    ];

    // Act
    app(UpsertMovies::class)->handle($rows);

    // Assert
    $genres = DB::table('movies')->where('imdb_id', 'tt0133093')->value('genres');
    expect($genres)->toBeNull();
});

it('stores a json array for a row with real genres', function () {
    // Arrange
    $rows = [
        ['tconst' => 'tt0133093', 'titleType' => 'movie', 'primaryTitle' => 'The Matrix', 'originalTitle' => 'The Matrix', 'startYear' => 1999, 'endYear' => null, 'runtimeMinutes' => 136, 'genres' => ['Action', 'Sci-Fi']],
    ];

    // Act
    app(UpsertMovies::class)->handle($rows);

    // Assert
    $genres = DB::table('movies')->where('imdb_id', 'tt0133093')->value('genres');
    expect($genres)->toBe(json_encode(['Action', 'Sci-Fi']));
});

it('stores the originating title type as a TitleType enum', function () {
    // Arrange
    $rows = [
        ['tconst' => 'tt0133093', 'titleType' => 'movie', 'primaryTitle' => 'The Matrix', 'originalTitle' => 'The Matrix', 'startYear' => 1999, 'endYear' => null, 'runtimeMinutes' => 136, 'genres' => ['Action', 'Sci-Fi']],
    ];

    // Act
    app(UpsertMovies::class)->handle($rows);

    // Assert
    $movie = Movie::query()->where('imdb_id', 'tt0133093')->firstOrFail();
    expect($movie->title_type)->toBe(TitleType::Movie);
});

it('updates the title type on re-upsert of the same tconst', function () {
    // Arrange
    app(UpsertMovies::class)->handle([
        ['tconst' => 'tt0133093', 'titleType' => 'movie', 'primaryTitle' => 'The Matrix', 'originalTitle' => 'The Matrix', 'startYear' => 1999, 'endYear' => null, 'runtimeMinutes' => 136, 'genres' => ['Action', 'Sci-Fi']],
    ]);

    // Act
    app(UpsertMovies::class)->handle([
        ['tconst' => 'tt0133093', 'titleType' => 'tvMovie', 'primaryTitle' => 'The Matrix', 'originalTitle' => 'The Matrix', 'startYear' => 1999, 'endYear' => null, 'runtimeMinutes' => 136, 'genres' => ['Action', 'Sci-Fi']],
    ]);

    // Assert
    $movie = Movie::query()->where('imdb_id', 'tt0133093')->firstOrFail();
    expect($movie->title_type)->toBe(TitleType::TvMovie);
});
