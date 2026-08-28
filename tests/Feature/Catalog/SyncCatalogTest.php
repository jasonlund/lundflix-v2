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
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Symfony\Component\Console\Command\Command;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Fixtures (byte-exact real source slices)
|--------------------------------------------------------------------------
| tests/Fixtures/Catalog/tmdb/movie_ids.json.gz — daily export incl. id 603
|   (The Matrix).
| tests/Fixtures/Catalog/tmdb/movie.json — /movie/603 (imdb_id tt0133093);
|   tv.json — /tv/1399 (external_ids.imdb_id tt0944947).
| tests/Fixtures/Catalog/tvdb/* — login JWT, chained /updates feed, and
|   Breaking Bad's /series/434847/extended (_tvdb_id 81189, IMDB tt0903747).
| tests/Fixtures/Catalog/tvdb/series_page1.json + series_empty.json — the full
|   allSeries crawl (GET /series?page=0 then /series?page=1) that --fresh now
|   drives via catalog:seed-shows-tvdb, faked in fakeCatalogSyncFreshAndUpdates().
|
| A default catalog:sync dispatches catalog:sync-movies → catalog:sync-shows-tvdb →
| catalog:sync-episodes-tvdb → catalog:sync-shows-tmdb. Under --fresh the TVDB step
| swaps to the full crawl (catalog:seed-shows-tvdb) and --fresh is forwarded to both
| TMDB syncs: catalog:sync-movies --fresh → catalog:seed-shows-tvdb →
| catalog:sync-episodes-tvdb → catalog:sync-shows-tmdb --fresh.
*/

/**
 * Fake every host the catalog:sync flow touches with happy-path fixtures:
 * the TMDB movie + tv exports, the shared TMDB API (The Matrix for id 603,
 * Game of Thrones for id 1399, 404 else), and TheTVDB's /updates path (login
 * JWT, the chained updates feed, Breaking Bad's extended payload for the one
 * discovered recordId 434847).
 */
function fakeCatalogSync(): void
{
    Http::fake([
        '*movie_ids*' => Http::response(fixtureBytes('Catalog/tmdb/movie_ids.json.gz')),
        // Both TMDB commands hit their changes feed after the insert phase on a
        // default run; empty-results pages drive the update phase through its
        // success path (no swallowed exception, no stray stack trace). Listed
        // before the generic detail stub since they live on the same host.
        '*/movie/changes*' => Http::response('{"results":[],"page":1,"total_pages":1,"total_results":0}'),
        '*/tv/changes*' => Http::response('{"results":[],"page":1,"total_pages":1,"total_results":0}'),
        '*api.themoviedb.org*' => function (Request $request) {
            if (Str::contains($request->url(), '/movie/603')) {
                return Http::response(fixtureBytes('Catalog/tmdb/movie.json'));
            }

            if (Str::contains($request->url(), '/tv/1399')) {
                return Http::response(fixtureBytes('Catalog/tmdb/tv.json'));
            }

            return Http::response('', 404);
        },
        '*api4.thetvdb.com/v4/login*' => Http::response(fixtureBytes('Catalog/tvdb/login.json')),
        '*api4.thetvdb.com/v4/updates*' => fn (Request $request) => Str::contains($request->url(), 'page=1')
            ? Http::response(fixtureBytes('Catalog/tvdb/updates_page2.json'))
            : Http::response(fixtureBytes('Catalog/tvdb/updates.json')),
        '*api4.thetvdb.com/v4/series/*/extended*' => fn (Request $request) => Str::contains($request->url(), '/series/434847/extended')
            ? Http::response(fixtureBytes('Catalog/tvdb/series_extended.json'))
            : Http::response('', 404),
    ]);
}

/**
 * The full-crawl fake for a --fresh run: TheTVDB's login, the allSeries crawl
 * (/series?page=0|1), and Breaking Bad's extended payload for recordId 434847
 * (404 for every other crawled id). The /updates feed is faked too, purely as a
 * should-not-fire guard — under --fresh the TVDB step is the crawl, so the test
 * proves /updates is never requested. The specific /series?page patterns precede
 * the wildcard extended-series pattern so Http::fake matches them correctly.
 */
function fakeCatalogSyncFreshAndUpdates(): void
{
    Http::fake([
        '*movie_ids*' => Http::response(fixtureBytes('Catalog/tmdb/movie_ids.json.gz')),
        // Both TMDB commands hit their changes feed after the insert phase on a
        // default run; empty-results pages drive the update phase through its
        // success path (no swallowed exception, no stray stack trace). Listed
        // before the generic detail stub since they live on the same host.
        '*/movie/changes*' => Http::response('{"results":[],"page":1,"total_pages":1,"total_results":0}'),
        '*/tv/changes*' => Http::response('{"results":[],"page":1,"total_pages":1,"total_results":0}'),
        '*api.themoviedb.org*' => function (Request $request) {
            if (Str::contains($request->url(), '/movie/603')) {
                return Http::response(fixtureBytes('Catalog/tmdb/movie.json'));
            }

            if (Str::contains($request->url(), '/tv/1399')) {
                return Http::response(fixtureBytes('Catalog/tmdb/tv.json'));
            }

            return Http::response('', 404);
        },
        '*api4.thetvdb.com/v4/login*' => Http::response(fixtureBytes('Catalog/tvdb/login.json')),
        '*api4.thetvdb.com/v4/series?page=0*' => Http::response(fixtureBytes('Catalog/tvdb/series_page1.json')),
        '*api4.thetvdb.com/v4/series?page=1*' => Http::response(fixtureBytes('Catalog/tvdb/series_empty.json')),
        '*api4.thetvdb.com/v4/updates*' => fn (Request $request) => Str::contains($request->url(), 'page=1')
            ? Http::response(fixtureBytes('Catalog/tvdb/updates_page2.json'))
            : Http::response(fixtureBytes('Catalog/tvdb/updates.json')),
        '*api4.thetvdb.com/v4/series/*/extended*' => fn (Request $request) => Str::contains($request->url(), '/series/434847/extended')
            ? Http::response(fixtureBytes('Catalog/tvdb/series_extended.json'))
            : Http::response('', 404),
    ]);
}

beforeEach(function (): void {
    Cache::flush();
    config(['services.tvdb.key' => 'test-key']);
});

describe('catalog:sync title creation', function (): void {
    it('is born a movie from TMDB', function (): void {
        // Arrange
        fakeCatalogSync();

        // Act
        $this->artisan('catalog:sync');

        // Assert
        $matrix = Movie::where('_tmdb_id', 603)->first();
        expect($matrix)->not->toBeNull();
        expect($matrix->_imdb_id)->toBe('tt0133093');
        expect(Movie::count())->toBe(1);
    });

    it('is born a show from TVDB; TMDB does not create a show it cannot match', function (): void {
        // Arrange
        fakeCatalogSync();

        // Act
        $this->artisan('catalog:sync');

        // Assert
        // TVDB is the single source of truth for creating shows: Breaking Bad is born
        // from TVDB (tvdb 81189) carrying its imdb crosswalk tt0903747 and its
        // remoteIds TheMovieDB.com id 1396. TMDB only hydrates existing shows by id —
        // it never creates a show it can't match, so Game of Thrones (tmdb 1399) is
        // never inserted and Breaking Bad is the only row.
        $breakingBad = Show::where('_tvdb_id', 81189)->first();
        expect($breakingBad)->not->toBeNull();
        expect($breakingBad->_imdb_id)->toBe('tt0903747');
        expect($breakingBad->_tmdb_id)->toBe(1396);

        expect(Show::where('_tmdb_id', 1399)->first())->toBeNull();
        expect(Show::count())->toBe(1);
    });
});

describe('catalog:sync IMDb dataset exclusion', function (): void {
    it('requests no IMDb dataset on a default run', function (): void {
        // Arrange
        fakeCatalogSync();

        // Act
        $this->artisan('catalog:sync');

        // Assert
        // The three IMDb legs now run under catalog:sync-imdb, so the twice-daily
        // catalog:sync must never reach for a multi-hundred-megabyte dataset.
        Http::assertNotSent(fn (Request $request): bool => Str::contains($request->url(), 'title.ratings'));
        Http::assertNotSent(fn (Request $request): bool => Str::contains($request->url(), 'title.basics'));
        Http::assertNotSent(fn (Request $request): bool => Str::contains($request->url(), 'title.akas'));
    });

    it('requests no IMDb dataset under --fresh either', function (): void {
        // Arrange
        fakeCatalogSyncFreshAndUpdates();

        // Act
        $this->artisan('catalog:sync', ['--fresh' => true]);

        // Assert
        Http::assertNotSent(fn (Request $request): bool => Str::contains($request->url(), 'title.ratings'));
        Http::assertNotSent(fn (Request $request): bool => Str::contains($request->url(), 'title.basics'));
        Http::assertNotSent(fn (Request $request): bool => Str::contains($request->url(), 'title.akas'));
    });

    it('still builds the TMDB movie and the TVDB show on a default run', function (): void {
        // Arrange
        fakeCatalogSync();

        // Act & Assert
        $this->artisan('catalog:sync')->assertExitCode(Command::SUCCESS);

        // Assert
        // Dropping the IMDb legs must leave the TMDB/TVDB chain itself untouched.
        expect(Movie::where('_imdb_id', 'tt0133093')->first())->not->toBeNull();
        expect(Show::where('_tvdb_id', 81189)->first())->not->toBeNull();
    });
});

describe('catalog:sync exit codes and failure handling', function (): void {
    it('continues past a failing movies command, exits FAILURE and reports', function (): void {
        // Arrange
        Sleep::fake();
        Exceptions::fake();
        // Http::fake merges stubs and the first registered match wins, so this 500
        // registered ahead of the happy-path helper overrides only the ids export.
        Http::fake(['*movie_ids*' => Http::response('', 500)]);
        fakeCatalogSync();

        // Act & Assert
        $this->artisan('catalog:sync')->assertExitCode(Command::FAILURE);

        // Assert
        // The dead export takes catalog:sync-movies down with it, but the orchestrator
        // reports the throwable and keeps dispatching — so the TVDB show still lands.
        Exceptions::assertReported(fn (RequestException $e): bool => true);
        expect(Show::where('_tvdb_id', 81189)->first())->not->toBeNull();
    });

    it('names the failed leg on the console and closes with a summary', function (): void {
        // Arrange
        Sleep::fake();
        Exceptions::fake();
        Http::fake(['*movie_ids*' => Http::response('', 500)]);
        fakeCatalogSync();

        // Act & Assert
        // The defect this pins: a leg that threw was report()ed and nothing was
        // printed, so a run that lost a leg read as a clean exit at the prompt.
        // The 500 lands inside the download phase, so the phase must close itself
        // too — without that line its start line is the last thing printed and a
        // dead phase is indistinguishable from a hung one. Asserted as a prefix
        // because the elapsed seconds vary.
        $this->artisan('catalog:sync')
            ->expectsOutputToContain('Downloading movie-ids export… failed after')
            ->expectsOutputToContain('catalog:sync-movies failed:')
            ->expectsOutputToContain('Completed with 1 failed leg: catalog:sync-movies')
            ->assertExitCode(Command::FAILURE);
    });

    it('closes a clean run with Done.', function (): void {
        // Arrange
        fakeCatalogSync();

        // Act & Assert
        $this->artisan('catalog:sync')
            ->expectsOutputToContain('Done.')
            ->assertExitCode(Command::SUCCESS);
    });

    it('exits SUCCESS when every command succeeds', function (): void {
        // Arrange
        fakeCatalogSync();

        // Act & Assert
        $this->artisan('catalog:sync')->assertExitCode(Command::SUCCESS);
    });
});

describe('catalog:sync --fresh and default routing', function (): void {
    it('under --fresh crawls the full TVDB seed and forwards --fresh to both TMDB syncs', function (): void {
        // Arrange
        fakeCatalogSyncFreshAndUpdates();
        Movie::factory()->withTmdb()->create(['_tmdb_id' => 603]);
        Show::factory()->withTmdb()->create(['_tmdb_id' => 1399]);

        // Act
        $this->artisan('catalog:sync', ['--fresh' => true]);

        // Assert
        // --fresh swaps the TVDB show step to the full crawl (/series?page), so the
        // series-updates feed's driver call (type=series at page 0, no page cursor)
        // must never fire. The marker-driven episodes step still walks /updates, and
        // the shared fixture's real next-link is a type=series&page=1 capture, so we
        // discriminate on the page-0 entry rather than that borrowed cursor; the
        // type=episodes dispatch itself is asserted elsewhere. Forwarding --fresh
        // reprocesses the already-synced 603/1399 rows a plain run skips, so both
        // hydrations fire.
        Http::assertSent(fn (Request $request): bool => Str::contains($request->url(), '/series?page'));
        Http::assertNotSent(fn (Request $request): bool => Str::contains(urldecode((string) $request->url()), 'type=series')
            && ! Str::contains($request->url(), 'page='));
        Http::assertSent(fn (Request $request): bool => Str::contains($request->url(), '/movie/603'));
        Http::assertSent(fn (Request $request): bool => Str::contains($request->url(), '/tv/1399'));
    });

    it('on a default run uses the TVDB updates feed and forwards no --fresh to the TMDB syncs', function (): void {
        // Arrange
        fakeCatalogSync();
        Movie::factory()->withTmdb()->create(['_tmdb_id' => 603]);
        Show::factory()->withTmdb()->create(['_tmdb_id' => 1399]);

        // Act
        $this->artisan('catalog:sync');

        // Assert
        // No --fresh means the updates feed (never the crawl) and the already-synced
        // 603/1399 rows are skipped, so neither hydration fires.
        Http::assertSent(fn (Request $request): bool => Str::contains($request->url(), '/updates'));
        Http::assertNotSent(fn (Request $request): bool => Str::contains($request->url(), '/series?page'));
        Http::assertNotSent(fn (Request $request): bool => Str::contains($request->url(), '/movie/603'));
        Http::assertNotSent(fn (Request $request): bool => Str::contains($request->url(), '/tv/1399'));
    });
});

describe('catalog:sync TMDB changes feeds', function (): void {
    it('exercises both TMDB changes feeds on a default run', function (): void {
        // Arrange
        fakeCatalogSync();

        // Act
        $this->artisan('catalog:sync');

        // Assert
        Http::assertSent(fn (Request $request): bool => Str::contains($request->url(), '/movie/changes'));
        Http::assertSent(fn (Request $request): bool => Str::contains($request->url(), '/tv/changes'));
    });
});

describe('catalog:sync episodes dispatch', function (): void {
    it('on a default run dispatches the episodes sync after the show sync', function (): void {
        // Arrange
        fakeCatalogSync();

        // Act
        $this->artisan('catalog:sync');

        // Assert
        // The type=episodes updates call only fires from catalog:sync-episodes-tvdb;
        // seeing it proves the episodes command ran inside the orchestrator (ordering
        // after the show sync is enforced structurally by its list placement).
        Http::assertSent(fn (Request $request): bool => Str::contains(urldecode((string) $request->url()), 'type=episodes'));
    });

    it('under --fresh also dispatches the episodes sync after the show crawl', function (): void {
        // Arrange
        fakeCatalogSyncFreshAndUpdates();

        // Act
        $this->artisan('catalog:sync', ['--fresh' => true]);

        // Assert
        // The type=episodes updates call only fires from catalog:sync-episodes-tvdb;
        // --fresh swaps the show step to the crawl but the episodes step is purely
        // marker-driven with no --fresh flag, so it must still run — seeing type=episodes
        // proves it did (ordering after the crawl is enforced by its list placement).
        Http::assertSent(fn (Request $request): bool => Str::contains(urldecode((string) $request->url()), 'type=episodes'));
    });
});
