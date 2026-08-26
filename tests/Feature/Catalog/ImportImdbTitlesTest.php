<?php

declare(strict_types=1);

use App\Domains\Catalog\Actions\ImportImdbTitles;
use App\Domains\Catalog\Models\Movie;
use App\Domains\Catalog\Models\Show;
use Illuminate\Support\Facades\DB;

/**
 * The batches below are in-memory copies of real `title.basics` rows from the
 * committed byte-exact capture `tests/Fixtures/Catalog/imdb/title.basics.tsv.gz`
 * — tt0000001 Carmencita, tt0133093 The Matrix, tt0137523 Fight Club, tt0816692
 * Interstellar, tt0903747 Breaking Bad — after ImdbDatasetService's casts
 * (`\N` => null, numerics => int, `genres` => list of strings). This slice
 * exercises the action directly, so no dataset is streamed. Only Breaking Bad
 * carries a non-null `endYear`; the movie rows cover the null case.
 */
it('writes the basics columns onto a matched movie', function (): void {
    // Arrange
    $movie = Movie::factory()->create();

    // Act
    resolve(ImportImdbTitles::class)->handle([
        $movie->_imdb_id => [
            'tconst' => $movie->_imdb_id,
            'titleType' => 'movie',
            'primaryTitle' => 'The Matrix',
            'originalTitle' => 'The Matrix',
            'startYear' => 1999,
            'endYear' => null,
            'runtimeMinutes' => 136,
            'genres' => ['Action', 'Sci-Fi'],
        ],
    ]);

    // Assert
    $fresh = Movie::query()->find($movie->id);
    expect($fresh->_imdb_titleType)->toBe('movie')
        ->and($fresh->_imdb_primaryTitle)->toBe('The Matrix')
        ->and($fresh->_imdb_originalTitle)->toBe('The Matrix')
        ->and($fresh->_imdb_startYear)->toBe(1999)
        ->and($fresh->_imdb_endYear)->toBeNull()
        ->and($fresh->_imdb_runtimeMinutes)->toBe(136)
        ->and($fresh->_imdb_genres)->toBe(['Action', 'Sci-Fi']);
});

it('writes the basics columns onto a matched show', function (): void {
    // Arrange
    $show = Show::factory()->create();

    // Act
    resolve(ImportImdbTitles::class)->handle([
        $show->_imdb_id => [
            'tconst' => $show->_imdb_id,
            'titleType' => 'tvSeries',
            'primaryTitle' => 'Breaking Bad',
            'originalTitle' => 'Breaking Bad',
            'startYear' => 2008,
            'endYear' => 2013,
            'runtimeMinutes' => 48,
            'genres' => ['Crime', 'Drama', 'Thriller'],
        ],
    ]);

    // Assert
    $fresh = Show::query()->find($show->id);
    expect($fresh->_imdb_titleType)->toBe('tvSeries')
        ->and($fresh->_imdb_primaryTitle)->toBe('Breaking Bad')
        ->and($fresh->_imdb_originalTitle)->toBe('Breaking Bad')
        ->and($fresh->_imdb_startYear)->toBe(2008)
        ->and($fresh->_imdb_endYear)->toBe(2013)
        ->and($fresh->_imdb_runtimeMinutes)->toBe(48)
        ->and($fresh->_imdb_genres)->toBe(['Crime', 'Drama', 'Thriller']);
});

it('persists the genres as a JSON list that reads back as an array', function (): void {
    // Arrange
    $movie = Movie::factory()->create();

    // Act
    resolve(ImportImdbTitles::class)->handle([
        $movie->_imdb_id => [
            'tconst' => $movie->_imdb_id,
            'titleType' => 'movie',
            'primaryTitle' => 'Interstellar',
            'originalTitle' => 'Interstellar',
            'startYear' => 2014,
            'endYear' => null,
            'runtimeMinutes' => 169,
            'genres' => ['Adventure', 'Drama', 'Sci-Fi'],
        ],
    ]);

    // The column is read raw as well as through the cast, so a comma-joined
    // string smuggled into the json column can't pass as a list.
    // Assert
    $stored = DB::table('movies')->where('id', $movie->id)->value('_imdb_genres');
    expect(json_decode((string) $stored, true))->toBe(['Adventure', 'Drama', 'Sci-Fi'])
        ->and(Movie::query()->find($movie->id)->_imdb_genres)->toBe(['Adventure', 'Drama', 'Sci-Fi']);
});

it('inserts nothing for a tconst with no matching title', function (): void {
    // Arrange
    $movie = Movie::factory()->create();

    // Act
    $result = resolve(ImportImdbTitles::class)->handle([
        $movie->_imdb_id => [
            'tconst' => $movie->_imdb_id,
            'titleType' => 'movie',
            'primaryTitle' => 'Fight Club',
            'originalTitle' => 'Fight Club',
            'startYear' => 1999,
            'endYear' => null,
            'runtimeMinutes' => 139,
            'genres' => ['Crime', 'Drama', 'Thriller'],
        ],
        'tt0000001' => [
            'tconst' => 'tt0000001',
            'titleType' => 'short',
            'primaryTitle' => 'Carmencita',
            'originalTitle' => 'Carmencita',
            'startYear' => 1894,
            'endYear' => null,
            'runtimeMinutes' => 1,
            'genres' => ['Documentary', 'Short'],
        ],
    ]);

    // Assert
    expect(Movie::query()->count())->toBe(1)
        ->and(Show::query()->count())->toBe(0)
        ->and(Movie::query()->where('_imdb_id', 'tt0000001')->exists())->toBeFalse()
        ->and(Movie::query()->find($movie->id)->_imdb_primaryTitle)->toBe('Fight Club')
        ->and($result)->toBe(['movies' => 1, 'shows' => 0]);
});

it('returns the matched counts per table for a mixed batch', function (): void {
    // Arrange
    $movie = Movie::factory()->create();
    $show = Show::factory()->create();

    // Act
    $result = resolve(ImportImdbTitles::class)->handle([
        $movie->_imdb_id => [
            'tconst' => $movie->_imdb_id,
            'titleType' => 'movie',
            'primaryTitle' => 'The Matrix',
            'originalTitle' => 'The Matrix',
            'startYear' => 1999,
            'endYear' => null,
            'runtimeMinutes' => 136,
            'genres' => ['Action', 'Sci-Fi'],
        ],
        $show->_imdb_id => [
            'tconst' => $show->_imdb_id,
            'titleType' => 'tvSeries',
            'primaryTitle' => 'Breaking Bad',
            'originalTitle' => 'Breaking Bad',
            'startYear' => 2008,
            'endYear' => 2013,
            'runtimeMinutes' => 48,
            'genres' => ['Crime', 'Drama', 'Thriller'],
        ],
        'tt0000001' => [
            'tconst' => 'tt0000001',
            'titleType' => 'short',
            'primaryTitle' => 'Carmencita',
            'originalTitle' => 'Carmencita',
            'startYear' => 1894,
            'endYear' => null,
            'runtimeMinutes' => 1,
            'genres' => ['Documentary', 'Short'],
        ],
    ]);

    // Assert
    expect($result)->toBe(['movies' => 1, 'shows' => 1]);
});

it('passes nothing to the search engine while still writing the basics columns', function (): void {
    // Arrange
    $movie = Movie::factory()->create();
    $show = Show::factory()->create();
    // Registered last so the factory saves' own create-time syncs aren't captured
    // — otherwise every row looks reindexed and nothing can ever look quiet.
    $capturedChunks = spyOnScoutEngine();

    // Act
    $result = resolve(ImportImdbTitles::class)->handle([
        $movie->_imdb_id => [
            'tconst' => $movie->_imdb_id,
            'titleType' => 'movie',
            'primaryTitle' => 'The Matrix',
            'originalTitle' => 'The Matrix',
            'startYear' => 1999,
            'endYear' => null,
            'runtimeMinutes' => 136,
            'genres' => ['Action', 'Sci-Fi'],
        ],
        $show->_imdb_id => [
            'tconst' => $show->_imdb_id,
            'titleType' => 'tvSeries',
            'primaryTitle' => 'Breaking Bad',
            'originalTitle' => 'Breaking Bad',
            'startYear' => 2008,
            'endYear' => 2013,
            'runtimeMinutes' => 48,
            'genres' => ['Crime', 'Drama', 'Thriller'],
        ],
    ]);

    // Assert
    expect(reindexedIds($capturedChunks()))->toBe([])
        ->and(Movie::query()->find($movie->id)->_imdb_primaryTitle)->toBe('The Matrix')
        ->and(Show::query()->find($show->id)->_imdb_primaryTitle)->toBe('Breaking Bad')
        ->and($result)->toBe(['movies' => 1, 'shows' => 1]);
});
