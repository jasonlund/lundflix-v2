<?php

declare(strict_types=1);

use App\Domains\Catalog\Models\Movie;
use App\Domains\Catalog\Models\Show;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Console\Command\Command;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Cache::flush();
    config(['services.tvdb.key' => 'test-key']);
});

/*
|--------------------------------------------------------------------------
| Fixtures (byte-exact real source slices)
|--------------------------------------------------------------------------
| tests/Fixtures/Catalog/imdb/title.ratings.tsv.gz — tt0133093 8.7/2252453,
|   tt0137523 8.8/2615814, tt0816692 8.7/2541567, tt0000001 5.7/2211
|   (no tt0903747 row, so Breaking Bad never gets ranked).
| tests/Fixtures/Catalog/tmdb/movie_ids.json.gz — daily export incl. id 603
|   (The Matrix); tv_series_ids.json.gz — incl. id 1399 (Game of Thrones).
| tests/Fixtures/Catalog/tmdb/movie.json — /movie/603 (imdb_id tt0133093);
|   tv.json — /tv/1399 (external_ids.imdb_id tt0944947).
| tests/Fixtures/Catalog/tvdb/* — login JWT, chained /updates feed, and
|   Breaking Bad's /series/434847/extended (_tvdb_id 81189, IMDB tt0903747).
| tests/Fixtures/Catalog/tvdb/series_page1.json + series_empty.json — responses
|   for the retired allSeries crawl (GET /series?page=0 then /series?page=1).
|   --fresh no longer crawls, so these are stubbed only in
|   fakeCatalogSyncFreshAndUpdates() as a should-not-fire guard: the test proves
|   /series?page is never requested. They drive no crawl — Breaking Bad's
|   extended payload is always served for updates recordId 434847.
|
| sync:catalog dispatches tmdb:sync-movies → tvdb:sync-shows →
| tmdb:sync-shows → imdb:import-ratings. There is no title.basics import in the
| flow, so title.basics is never requested and is not faked.
*/

/**
 * Fake every host the sync:catalog flow touches with happy-path fixtures:
 * the IMDb ratings dataset, the TMDB movie + tv exports, the shared TMDB API
 * (The Matrix for id 603, Game of Thrones for id 1399, 404 else), and TheTVDB's
 * /updates path (login JWT, the chained updates feed, Breaking Bad's extended
 * payload for the one discovered recordId 434847).
 */
function fakeCatalogSync(): void
{
    Http::fake([
        '*title.ratings*' => Http::response(fixtureBytes('Catalog/imdb/title.ratings.tsv.gz')),
        '*movie_ids*' => Http::response(fixtureBytes('Catalog/tmdb/movie_ids.json.gz')),
        '*tv_series_ids*' => Http::response(fixtureBytes('Catalog/tmdb/tv_series_ids.json.gz')),
        '*api.themoviedb.org*' => function (Request $request) {
            if (str_contains($request->url(), '/movie/603')) {
                return Http::response(fixtureBytes('Catalog/tmdb/movie.json'));
            }

            if (str_contains($request->url(), '/tv/1399')) {
                return Http::response(fixtureBytes('Catalog/tmdb/tv.json'));
            }

            return Http::response('', 404);
        },
        '*api4.thetvdb.com/v4/login*' => Http::response(fixtureBytes('Catalog/tvdb/login.json')),
        '*api4.thetvdb.com/v4/updates*' => fn (Request $request) => str_contains($request->url(), 'page=1')
            ? Http::response(fixtureBytes('Catalog/tvdb/updates_page2.json'))
            : Http::response(fixtureBytes('Catalog/tvdb/updates.json')),
        '*api4.thetvdb.com/v4/series/*/extended*' => fn (Request $request) => str_contains($request->url(), '/series/434847/extended')
            ? Http::response(fixtureBytes('Catalog/tvdb/series_extended.json'))
            : Http::response('', 404),
    ]);
}

/**
 * The happy-path updates fake plus stubs for the retired allSeries crawl: both
 * TheTVDB's /updates feed AND /series?page=0|1 are faked. The page stubs exist
 * only so the test can prove --fresh never crawls them — a should-not-fire guard
 * — while the run stays on /updates. Breaking Bad's extended payload is served
 * for updates recordId 434847. The specific /series?page patterns precede the
 * wildcard extended-series pattern so Http::fake matches them correctly.
 */
function fakeCatalogSyncFreshAndUpdates(): void
{
    Http::fake([
        '*title.ratings*' => Http::response(fixtureBytes('Catalog/imdb/title.ratings.tsv.gz')),
        '*movie_ids*' => Http::response(fixtureBytes('Catalog/tmdb/movie_ids.json.gz')),
        '*tv_series_ids*' => Http::response(fixtureBytes('Catalog/tmdb/tv_series_ids.json.gz')),
        '*api.themoviedb.org*' => function (Request $request) {
            if (str_contains($request->url(), '/movie/603')) {
                return Http::response(fixtureBytes('Catalog/tmdb/movie.json'));
            }

            if (str_contains($request->url(), '/tv/1399')) {
                return Http::response(fixtureBytes('Catalog/tmdb/tv.json'));
            }

            return Http::response('', 404);
        },
        '*api4.thetvdb.com/v4/login*' => Http::response(fixtureBytes('Catalog/tvdb/login.json')),
        '*api4.thetvdb.com/v4/series?page=0*' => Http::response(fixtureBytes('Catalog/tvdb/series_page1.json')),
        '*api4.thetvdb.com/v4/series?page=1*' => Http::response(fixtureBytes('Catalog/tvdb/series_empty.json')),
        '*api4.thetvdb.com/v4/updates*' => fn (Request $request) => str_contains($request->url(), 'page=1')
            ? Http::response(fixtureBytes('Catalog/tvdb/updates_page2.json'))
            : Http::response(fixtureBytes('Catalog/tvdb/updates.json')),
        '*api4.thetvdb.com/v4/series/*/extended*' => fn (Request $request) => str_contains($request->url(), '/series/434847/extended')
            ? Http::response(fixtureBytes('Catalog/tvdb/series_extended.json'))
            : Http::response('', 404),
    ]);
}

it('is born a movie from TMDB', function (): void {
    // Arrange
    fakeCatalogSync();

    // Act
    $this->artisan('sync:catalog');

    // Assert
    $matrix = Movie::where('_tmdb_id', 603)->first();
    expect($matrix)->not->toBeNull();
    expect($matrix->_imdb_id)->toBe('tt0133093');
    expect(Movie::count())->toBe(1);
});

it('is born a show from TVDB, and TMDB inserts a tmdb-only show matching none', function (): void {
    // Arrange
    fakeCatalogSync();

    // Act
    $this->artisan('sync:catalog');

    // Assert
    // Breaking Bad is born from TVDB; Game of Thrones shares none of its source
    // ids, so TMDB inserts it as its own tmdb-only row rather than dropping it —
    // two independent shows, each source-of-truth for its own row.
    $breakingBad = Show::where('_tvdb_id', 81189)->first();
    expect($breakingBad)->not->toBeNull();
    expect($breakingBad->_imdb_id)->toBe('tt0903747');

    $gameOfThrones = Show::where('_tmdb_id', 1399)->first();
    expect($gameOfThrones)->not->toBeNull();
    expect($gameOfThrones->_tvdb_id)->toBeNull();
    expect(Show::count())->toBe(2);
});

it('applies IMDb ratings last by _imdb_id', function (): void {
    // Arrange
    fakeCatalogSync();

    // Act
    $this->artisan('sync:catalog');

    // Assert
    $matrix = Movie::where('_imdb_id', 'tt0133093')->firstOrFail();
    expect($matrix->_imdb_num_votes)->toBe(2252453);
    expect($matrix->_imdb_average_rating)->toBe(8.7);

    $breakingBad = Show::where('_imdb_id', 'tt0903747')->firstOrFail();
    expect($breakingBad->_imdb_num_votes)->toBeNull();
});

it('never runs the removed import-titles command', function (): void {
    // Arrange
    fakeCatalogSync();

    // Act
    $this->artisan('sync:catalog');

    // Assert
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'title.basics'));
});

it('exits SUCCESS when every command succeeds', function (): void {
    // Arrange
    fakeCatalogSync();

    // Act & Assert
    $this->artisan('sync:catalog')->assertExitCode(Command::SUCCESS);
});

it('continues past a failing show command, exits FAILURE and reports', function (): void {
    // Arrange
    Exceptions::fake();
    Http::fake([
        '*title.ratings*' => Http::response(fixtureBytes('Catalog/imdb/title.ratings.tsv.gz')),
        '*movie_ids*' => Http::response(fixtureBytes('Catalog/tmdb/movie_ids.json.gz')),
        '*tv_series_ids*' => Http::response('', 500),
        '*api.themoviedb.org*' => function (Request $request) {
            if (str_contains($request->url(), '/movie/603')) {
                return Http::response(fixtureBytes('Catalog/tmdb/movie.json'));
            }

            if (str_contains($request->url(), '/tv/1399')) {
                return Http::response(fixtureBytes('Catalog/tmdb/tv.json'));
            }

            return Http::response('', 404);
        },
        '*api4.thetvdb.com/v4/login*' => Http::response(fixtureBytes('Catalog/tvdb/login.json')),
        '*api4.thetvdb.com/v4/updates*' => fn (Request $request) => str_contains($request->url(), 'page=1')
            ? Http::response(fixtureBytes('Catalog/tvdb/updates_page2.json'))
            : Http::response(fixtureBytes('Catalog/tvdb/updates.json')),
        '*api4.thetvdb.com/v4/series/*/extended*' => fn (Request $request) => str_contains($request->url(), '/series/434847/extended')
            ? Http::response(fixtureBytes('Catalog/tvdb/series_extended.json'))
            : Http::response('', 404),
    ]);

    // Act
    $this->artisan('sync:catalog')->assertExitCode(Command::FAILURE);

    // Assert
    Exceptions::assertReported(fn (RequestException $e): bool => true);
    expect(Show::where('_tvdb_id', 81189)->first())->not->toBeNull();
});

it('passing --fresh to sync:catalog no longer crawls TVDB', function (): void {
    // Arrange
    fakeCatalogSyncFreshAndUpdates();

    // Act
    $this->artisan('sync:catalog', ['--fresh' => true]);

    // Assert
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/series?page'));
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/updates'));
});

it('uses the TVDB updates feed on a default run', function (): void {
    // Arrange
    fakeCatalogSync();

    // Act
    $this->artisan('sync:catalog');

    // Assert
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/updates'));
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/series?page'));
});
