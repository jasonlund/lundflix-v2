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

it('maps cast rows to movie columns and returns the upserted count', function (): void {
    // Arrange
    $rows = [
        ['tconst' => 'tt0133093', 'titleType' => 'movie', 'primaryTitle' => 'The Matrix', 'originalTitle' => 'The Matrix', 'startYear' => 1999, 'endYear' => null, 'runtimeMinutes' => 136, 'genres' => ['Action', 'Sci-Fi']],
        ['tconst' => 'tt0137523', 'titleType' => 'movie', 'primaryTitle' => 'Fight Club', 'originalTitle' => 'Fight Club', 'startYear' => 1999, 'endYear' => null, 'runtimeMinutes' => 139, 'genres' => ['Drama']],
        ['tconst' => 'tt0110912', 'titleType' => 'movie', 'primaryTitle' => 'Pulp Fiction', 'originalTitle' => 'Pulp Fiction', 'startYear' => 1994, 'endYear' => null, 'runtimeMinutes' => 154, 'genres' => ['Crime', 'Drama']],
    ];

    // Act
    $count = resolve(UpsertMovies::class)->handle($rows);

    // Assert
    expect($count)->toBe(3);
    $this->assertDatabaseHas('movies', ['_imdb_id' => 'tt0133093', '_imdb_primary_title' => 'The Matrix', '_imdb_start_year' => 1999, '_imdb_runtime_minutes' => 136]);
    $this->assertDatabaseHas('movies', ['_imdb_id' => 'tt0137523', '_imdb_primary_title' => 'Fight Club', '_imdb_start_year' => 1999, '_imdb_runtime_minutes' => 139]);
    $this->assertDatabaseHas('movies', ['_imdb_id' => 'tt0110912', '_imdb_primary_title' => 'Pulp Fiction', '_imdb_start_year' => 1994, '_imdb_runtime_minutes' => 154]);
});

it('drops unknown genre values without throwing', function (): void {
    // Arrange
    $rows = [
        ['tconst' => 'tt0133093', 'titleType' => 'movie', 'primaryTitle' => 'The Matrix', 'originalTitle' => 'The Matrix', 'startYear' => 1999, 'endYear' => null, 'runtimeMinutes' => 136, 'genres' => ['Action', 'NotAGenre']],
    ];

    // Act
    resolve(UpsertMovies::class)->handle($rows);

    // Assert
    $movie = Movie::query()->where('_imdb_id', 'tt0133093')->firstOrFail();
    expect($movie->_imdb_genres->all())->toContain(Genre::Action)
        ->and($movie->_imdb_genres->all())->toEqual([Genre::Action]);
});

it('stores raw genres including unknown values', function (): void {
    // Arrange
    $rows = [
        ['tconst' => 'tt0133093', 'titleType' => 'movie', 'primaryTitle' => 'The Matrix', 'originalTitle' => 'The Matrix', 'startYear' => 1999, 'endYear' => null, 'runtimeMinutes' => 136, 'genres' => ['Action', 'NotAGenre']],
    ];

    // Act
    resolve(UpsertMovies::class)->handle($rows);

    // Assert
    $genres = DB::table('movies')->where('_imdb_id', 'tt0133093')->value('_imdb_genres');
    expect($genres)->toBe(json_encode(['Action', 'NotAGenre']));
});

it('maps genres to Genre cases dropping unknown on read', function (): void {
    // Arrange
    $rows = [
        ['tconst' => 'tt0133093', 'titleType' => 'movie', 'primaryTitle' => 'The Matrix', 'originalTitle' => 'The Matrix', 'startYear' => 1999, 'endYear' => null, 'runtimeMinutes' => 136, 'genres' => ['Action', 'NotAGenre']],
    ];

    // Act
    resolve(UpsertMovies::class)->handle($rows);

    // Assert
    $movie = Movie::query()->where('_imdb_id', 'tt0133093')->firstOrFail();
    expect($movie->_imdb_genres->all())->toEqual([Genre::Action]);
});

it('preserves num_votes and average_rating on re-upsert', function (): void {
    // Arrange
    $existing = Movie::factory()->create([
        '_imdb_id' => 'tt0133093',
        '_imdb_primary_title' => 'Old Title',
        '_imdb_start_year' => 1980,
        '_imdb_runtime_minutes' => 90,
        '_imdb_genres' => [Genre::Horror],
    ]);
    $originalVotes = $existing->_imdb_num_votes;
    $originalRating = $existing->_imdb_average_rating;

    // Act
    resolve(UpsertMovies::class)->handle([
        ['tconst' => 'tt0133093', 'titleType' => 'movie', 'primaryTitle' => 'The Matrix', 'originalTitle' => 'The Matrix', 'startYear' => 1999, 'endYear' => null, 'runtimeMinutes' => 136, 'genres' => ['Action', 'Sci-Fi']],
    ]);

    // Assert
    $fresh = Movie::query()->where('_imdb_id', 'tt0133093')->firstOrFail();
    expect(Movie::query()->count())->toBe(1)
        ->and($fresh->_imdb_primary_title)->toBe('The Matrix')
        ->and($fresh->_imdb_start_year)->toBe(1999)
        ->and($fresh->_imdb_runtime_minutes)->toBe(136)
        ->and($fresh->_imdb_genres->all())->toEqual([Genre::Action, Genre::SciFi])
        ->and($fresh->_imdb_num_votes)->toBe($originalVotes)
        ->and($fresh->_imdb_average_rating)->toBe($originalRating);
});

it('persists genres readable back through the enum-collection cast', function (): void {
    // Arrange
    $rows = [
        ['tconst' => 'tt0133093', 'titleType' => 'movie', 'primaryTitle' => 'The Matrix', 'originalTitle' => 'The Matrix', 'startYear' => 1999, 'endYear' => null, 'runtimeMinutes' => 136, 'genres' => ['Action', 'Sci-Fi']],
    ];

    // Act
    resolve(UpsertMovies::class)->handle($rows);

    // Assert
    $movie = Movie::query()->where('_imdb_id', 'tt0133093')->firstOrFail();
    expect($movie->_imdb_genres->all())->toEqual([Genre::Action, Genre::SciFi]);
});

it('stores SQL NULL for a row whose genres field is null, not the string "[]"', function (): void {
    // Arrange
    $rows = [
        ['tconst' => 'tt0133093', 'titleType' => 'movie', 'primaryTitle' => 'The Matrix', 'originalTitle' => 'The Matrix', 'startYear' => 1999, 'endYear' => null, 'runtimeMinutes' => 136, 'genres' => null],
    ];

    // Act
    resolve(UpsertMovies::class)->handle($rows);

    // Assert
    $genres = DB::table('movies')->where('_imdb_id', 'tt0133093')->value('_imdb_genres');
    expect($genres)->toBeNull();
});

it('stores a json array for a row with real genres', function (): void {
    // Arrange
    $rows = [
        ['tconst' => 'tt0133093', 'titleType' => 'movie', 'primaryTitle' => 'The Matrix', 'originalTitle' => 'The Matrix', 'startYear' => 1999, 'endYear' => null, 'runtimeMinutes' => 136, 'genres' => ['Action', 'Sci-Fi']],
    ];

    // Act
    resolve(UpsertMovies::class)->handle($rows);

    // Assert
    $genres = DB::table('movies')->where('_imdb_id', 'tt0133093')->value('_imdb_genres');
    expect($genres)->toBe(json_encode(['Action', 'Sci-Fi']));
});

it('stores the originating title type as a TitleType enum', function (): void {
    // Arrange
    $rows = [
        ['tconst' => 'tt0133093', 'titleType' => 'movie', 'primaryTitle' => 'The Matrix', 'originalTitle' => 'The Matrix', 'startYear' => 1999, 'endYear' => null, 'runtimeMinutes' => 136, 'genres' => ['Action', 'Sci-Fi']],
    ];

    // Act
    resolve(UpsertMovies::class)->handle($rows);

    // Assert
    $movie = Movie::query()->where('_imdb_id', 'tt0133093')->firstOrFail();
    expect($movie->_imdb_title_type)->toBe(TitleType::Movie);
});

it('updates the title type on re-upsert of the same tconst', function (): void {
    // Arrange
    resolve(UpsertMovies::class)->handle([
        ['tconst' => 'tt0133093', 'titleType' => 'movie', 'primaryTitle' => 'The Matrix', 'originalTitle' => 'The Matrix', 'startYear' => 1999, 'endYear' => null, 'runtimeMinutes' => 136, 'genres' => ['Action', 'Sci-Fi']],
    ]);

    // Act
    resolve(UpsertMovies::class)->handle([
        ['tconst' => 'tt0133093', 'titleType' => 'tvMovie', 'primaryTitle' => 'The Matrix', 'originalTitle' => 'The Matrix', 'startYear' => 1999, 'endYear' => null, 'runtimeMinutes' => 136, 'genres' => ['Action', 'Sci-Fi']],
    ]);

    // Assert
    $movie = Movie::query()->where('_imdb_id', 'tt0133093')->firstOrFail();
    expect($movie->_imdb_title_type)->toBe(TitleType::TvMovie);
});
