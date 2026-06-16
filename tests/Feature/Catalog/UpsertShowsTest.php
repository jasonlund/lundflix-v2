<?php

use App\Domains\Catalog\Actions\UpsertShows;
use App\Domains\Catalog\Enums\Genre;
use App\Domains\Catalog\Enums\TitleType;
use App\Domains\Catalog\Models\Show;

/*
|--------------------------------------------------------------------------
| Cast-row inputs are hand-built plain PHP arrays, mirroring the exact shape
| produced by ImdbDatasetService::rows() (genres already an array of strings,
| startYear/endYear/runtimeMinutes already int|null). These are synthetic-but-
| realistic inputs to the action, NOT external-response fixtures.
|--------------------------------------------------------------------------
*/

it('maps cast rows to show columns and returns the upserted count', function () {
    // Arrange
    $rows = [
        ['tconst' => 'tt0047766', 'titleType' => 'tvSeries', 'primaryTitle' => 'Quatermass II', 'originalTitle' => 'Quatermass II', 'startYear' => 1955, 'endYear' => 1955, 'runtimeMinutes' => 30, 'genres' => ['Drama', 'Horror', 'Sci-Fi']],
        ['tconst' => 'tt0903747', 'titleType' => 'tvSeries', 'primaryTitle' => 'Breaking Bad', 'originalTitle' => 'Breaking Bad', 'startYear' => 2008, 'endYear' => 2013, 'runtimeMinutes' => 49, 'genres' => ['Crime', 'Drama', 'Thriller']],
        ['tconst' => 'tt0944947', 'titleType' => 'tvSeries', 'primaryTitle' => 'Game of Thrones', 'originalTitle' => 'Game of Thrones', 'startYear' => 2011, 'endYear' => null, 'runtimeMinutes' => 57, 'genres' => ['Action', 'Adventure', 'Drama']],
    ];

    // Act
    $count = app(UpsertShows::class)->handle($rows);

    // Assert
    expect($count)->toBe(3);
    $this->assertDatabaseHas('shows', ['imdb_id' => 'tt0047766', 'title' => 'Quatermass II', 'start_year' => 1955, 'end_year' => 1955, 'runtime' => 30]);
    $this->assertDatabaseHas('shows', ['imdb_id' => 'tt0903747', 'title' => 'Breaking Bad', 'start_year' => 2008, 'end_year' => 2013, 'runtime' => 49]);
    $this->assertDatabaseHas('shows', ['imdb_id' => 'tt0944947', 'title' => 'Game of Thrones', 'start_year' => 2011, 'end_year' => null, 'runtime' => 57]);
});

it('drops unknown genre values without throwing', function () {
    // Arrange
    $rows = [
        ['tconst' => 'tt0047766', 'titleType' => 'tvSeries', 'primaryTitle' => 'Quatermass II', 'originalTitle' => 'Quatermass II', 'startYear' => 1955, 'endYear' => 1955, 'runtimeMinutes' => 30, 'genres' => ['Drama', 'NotAGenre']],
    ];

    // Act
    app(UpsertShows::class)->handle($rows);

    // Assert
    $show = Show::query()->where('imdb_id', 'tt0047766')->firstOrFail();
    expect($show->genres->all())->toContain(Genre::Drama)
        ->and($show->genres->all())->toEqual([Genre::Drama]);
});

it('preserves num_votes and average_rating on re-upsert', function () {
    // Arrange
    $existing = Show::factory()->create([
        'imdb_id' => 'tt0047766',
        'title' => 'Old Title',
        'start_year' => 1980,
        'end_year' => 1981,
        'runtime' => 25,
        'genres' => [Genre::Horror],
    ]);
    $originalVotes = $existing->num_votes;
    $originalRating = $existing->average_rating;

    // Act
    app(UpsertShows::class)->handle([
        ['tconst' => 'tt0047766', 'titleType' => 'tvSeries', 'primaryTitle' => 'Quatermass II', 'originalTitle' => 'Quatermass II', 'startYear' => 1955, 'endYear' => 1955, 'runtimeMinutes' => 30, 'genres' => ['Drama', 'Horror', 'Sci-Fi']],
    ]);

    // Assert
    $fresh = Show::query()->where('imdb_id', 'tt0047766')->firstOrFail();
    expect(Show::query()->count())->toBe(1)
        ->and($fresh->title)->toBe('Quatermass II')
        ->and($fresh->start_year)->toBe(1955)
        ->and($fresh->end_year)->toBe(1955)
        ->and($fresh->runtime)->toBe(30)
        ->and($fresh->genres->all())->toEqual([Genre::Drama, Genre::Horror, Genre::SciFi])
        ->and($fresh->num_votes)->toBe($originalVotes)
        ->and($fresh->average_rating)->toBe($originalRating);
});

it('persists genres readable back through the enum-collection cast', function () {
    // Arrange
    $rows = [
        ['tconst' => 'tt0047766', 'titleType' => 'tvSeries', 'primaryTitle' => 'Quatermass II', 'originalTitle' => 'Quatermass II', 'startYear' => 1955, 'endYear' => 1955, 'runtimeMinutes' => 30, 'genres' => ['Drama', 'Horror', 'Sci-Fi']],
    ];

    // Act
    app(UpsertShows::class)->handle($rows);

    // Assert
    $show = Show::query()->where('imdb_id', 'tt0047766')->firstOrFail();
    expect($show->genres->all())->toEqual([Genre::Drama, Genre::Horror, Genre::SciFi]);
});

it('stores the originating title type as a TitleType enum', function () {
    // Arrange
    $rows = [
        ['tconst' => 'tt0047766', 'titleType' => 'tvSeries', 'primaryTitle' => 'Quatermass II', 'originalTitle' => 'Quatermass II', 'startYear' => 1955, 'endYear' => 1955, 'runtimeMinutes' => 30, 'genres' => ['Drama', 'Horror', 'Sci-Fi']],
    ];

    // Act
    app(UpsertShows::class)->handle($rows);

    // Assert
    $show = Show::query()->where('imdb_id', 'tt0047766')->firstOrFail();
    expect($show->title_type)->toBe(TitleType::TvSeries);
});
