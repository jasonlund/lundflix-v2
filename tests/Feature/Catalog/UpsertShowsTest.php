<?php

declare(strict_types=1);

use App\Domains\Catalog\Actions\UpsertShows;
use App\Domains\Catalog\Enums\Genre;
use App\Domains\Catalog\Enums\TitleType;
use App\Domains\Catalog\Models\Show;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Cast-row inputs are hand-built plain PHP arrays, mirroring the exact shape
| produced by ImdbDatasetService::rows() (genres already an array of strings,
| startYear/endYear/runtimeMinutes already int|null). These are synthetic-but-
| realistic inputs to the action, NOT external-response fixtures.
|--------------------------------------------------------------------------
*/

it('maps cast rows to show columns and returns the upserted count', function (): void {
    // Arrange
    $rows = [
        ['tconst' => 'tt0047766', 'titleType' => 'tvSeries', 'primaryTitle' => 'Quatermass II', 'originalTitle' => 'Quatermass II', 'startYear' => 1955, 'endYear' => 1955, 'runtimeMinutes' => 30, 'genres' => ['Drama', 'Horror', 'Sci-Fi']],
        ['tconst' => 'tt0903747', 'titleType' => 'tvSeries', 'primaryTitle' => 'Breaking Bad', 'originalTitle' => 'Breaking Bad', 'startYear' => 2008, 'endYear' => 2013, 'runtimeMinutes' => 49, 'genres' => ['Crime', 'Drama', 'Thriller']],
        ['tconst' => 'tt0944947', 'titleType' => 'tvSeries', 'primaryTitle' => 'Game of Thrones', 'originalTitle' => 'Game of Thrones', 'startYear' => 2011, 'endYear' => null, 'runtimeMinutes' => 57, 'genres' => ['Action', 'Adventure', 'Drama']],
    ];

    // Act
    $count = resolve(UpsertShows::class)->handle($rows);

    // Assert
    expect($count)->toBe(3);
    $this->assertDatabaseHas('shows', ['_imdb_id' => 'tt0047766', '_imdb_primary_title' => 'Quatermass II', '_imdb_start_year' => 1955, '_imdb_end_year' => 1955, '_imdb_runtime_minutes' => 30]);
    $this->assertDatabaseHas('shows', ['_imdb_id' => 'tt0903747', '_imdb_primary_title' => 'Breaking Bad', '_imdb_start_year' => 2008, '_imdb_end_year' => 2013, '_imdb_runtime_minutes' => 49]);
    $this->assertDatabaseHas('shows', ['_imdb_id' => 'tt0944947', '_imdb_primary_title' => 'Game of Thrones', '_imdb_start_year' => 2011, '_imdb_end_year' => null, '_imdb_runtime_minutes' => 57]);
});

it('drops unknown genre values without throwing', function (): void {
    // Arrange
    $rows = [
        ['tconst' => 'tt0047766', 'titleType' => 'tvSeries', 'primaryTitle' => 'Quatermass II', 'originalTitle' => 'Quatermass II', 'startYear' => 1955, 'endYear' => 1955, 'runtimeMinutes' => 30, 'genres' => ['Drama', 'NotAGenre']],
    ];

    // Act
    resolve(UpsertShows::class)->handle($rows);

    // Assert
    $show = Show::query()->where('_imdb_id', 'tt0047766')->firstOrFail();
    expect($show->_imdb_genres->all())->toContain(Genre::Drama)
        ->and($show->_imdb_genres->all())->toEqual([Genre::Drama]);
});

it('stores raw genres including unknown values', function (): void {
    // Arrange
    $rows = [
        ['tconst' => 'tt0047766', 'titleType' => 'tvSeries', 'primaryTitle' => 'Quatermass II', 'originalTitle' => 'Quatermass II', 'startYear' => 1955, 'endYear' => 1955, 'runtimeMinutes' => 30, 'genres' => ['Drama', 'NotAGenre']],
    ];

    // Act
    resolve(UpsertShows::class)->handle($rows);

    // Assert
    $genres = DB::table('shows')->where('_imdb_id', 'tt0047766')->value('_imdb_genres');
    expect($genres)->toBe(json_encode(['Drama', 'NotAGenre']));
});

it('maps genres to Genre cases dropping unknown on read', function (): void {
    // Arrange
    $rows = [
        ['tconst' => 'tt0047766', 'titleType' => 'tvSeries', 'primaryTitle' => 'Quatermass II', 'originalTitle' => 'Quatermass II', 'startYear' => 1955, 'endYear' => 1955, 'runtimeMinutes' => 30, 'genres' => ['Drama', 'NotAGenre']],
    ];

    // Act
    resolve(UpsertShows::class)->handle($rows);

    // Assert
    $show = Show::query()->where('_imdb_id', 'tt0047766')->firstOrFail();
    expect($show->_imdb_genres->all())->toEqual([Genre::Drama]);
});

it('preserves num_votes and average_rating on re-upsert', function (): void {
    // Arrange
    $existing = Show::factory()->create([
        '_imdb_id' => 'tt0047766',
        '_imdb_primary_title' => 'Old Title',
        '_imdb_start_year' => 1980,
        '_imdb_end_year' => 1981,
        '_imdb_runtime_minutes' => 25,
        '_imdb_genres' => [Genre::Horror],
    ]);
    $originalVotes = $existing->_imdb_num_votes;
    $originalRating = $existing->_imdb_average_rating;

    // Act
    resolve(UpsertShows::class)->handle([
        ['tconst' => 'tt0047766', 'titleType' => 'tvSeries', 'primaryTitle' => 'Quatermass II', 'originalTitle' => 'Quatermass II', 'startYear' => 1955, 'endYear' => 1955, 'runtimeMinutes' => 30, 'genres' => ['Drama', 'Horror', 'Sci-Fi']],
    ]);

    // Assert
    $fresh = Show::query()->where('_imdb_id', 'tt0047766')->firstOrFail();
    expect(Show::query()->count())->toBe(1)
        ->and($fresh->_imdb_primary_title)->toBe('Quatermass II')
        ->and($fresh->_imdb_start_year)->toBe(1955)
        ->and($fresh->_imdb_end_year)->toBe(1955)
        ->and($fresh->_imdb_runtime_minutes)->toBe(30)
        ->and($fresh->_imdb_genres->all())->toEqual([Genre::Drama, Genre::Horror, Genre::SciFi])
        ->and($fresh->_imdb_num_votes)->toBe($originalVotes)
        ->and($fresh->_imdb_average_rating)->toBe($originalRating);
});

it('persists genres readable back through the enum-collection cast', function (): void {
    // Arrange
    $rows = [
        ['tconst' => 'tt0047766', 'titleType' => 'tvSeries', 'primaryTitle' => 'Quatermass II', 'originalTitle' => 'Quatermass II', 'startYear' => 1955, 'endYear' => 1955, 'runtimeMinutes' => 30, 'genres' => ['Drama', 'Horror', 'Sci-Fi']],
    ];

    // Act
    resolve(UpsertShows::class)->handle($rows);

    // Assert
    $show = Show::query()->where('_imdb_id', 'tt0047766')->firstOrFail();
    expect($show->_imdb_genres->all())->toEqual([Genre::Drama, Genre::Horror, Genre::SciFi]);
});

it('stores SQL NULL for a row whose genres field is null, not the string "[]"', function (): void {
    // Arrange
    $rows = [
        ['tconst' => 'tt0047766', 'titleType' => 'tvSeries', 'primaryTitle' => 'Quatermass II', 'originalTitle' => 'Quatermass II', 'startYear' => 1955, 'endYear' => 1955, 'runtimeMinutes' => 30, 'genres' => null],
    ];

    // Act
    resolve(UpsertShows::class)->handle($rows);

    // Assert
    $genres = DB::table('shows')->where('_imdb_id', 'tt0047766')->value('_imdb_genres');
    expect($genres)->toBeNull();
});

it('stores a json array for a row with real genres', function (): void {
    // Arrange
    $rows = [
        ['tconst' => 'tt0047766', 'titleType' => 'tvSeries', 'primaryTitle' => 'Quatermass II', 'originalTitle' => 'Quatermass II', 'startYear' => 1955, 'endYear' => 1955, 'runtimeMinutes' => 30, 'genres' => ['Drama', 'Horror', 'Sci-Fi']],
    ];

    // Act
    resolve(UpsertShows::class)->handle($rows);

    // Assert
    $genres = DB::table('shows')->where('_imdb_id', 'tt0047766')->value('_imdb_genres');
    expect($genres)->toBe(json_encode(['Drama', 'Horror', 'Sci-Fi']));
});

it('stores the originating title type as a TitleType enum', function (): void {
    // Arrange
    $rows = [
        ['tconst' => 'tt0047766', 'titleType' => 'tvSeries', 'primaryTitle' => 'Quatermass II', 'originalTitle' => 'Quatermass II', 'startYear' => 1955, 'endYear' => 1955, 'runtimeMinutes' => 30, 'genres' => ['Drama', 'Horror', 'Sci-Fi']],
    ];

    // Act
    resolve(UpsertShows::class)->handle($rows);

    // Assert
    $show = Show::query()->where('_imdb_id', 'tt0047766')->firstOrFail();
    expect($show->_imdb_title_type)->toBe(TitleType::TvSeries);
});
