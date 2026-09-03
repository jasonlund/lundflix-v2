<?php

declare(strict_types=1);

use App\Domains\Catalog\Exceptions\TmdbRequestFailed;
use App\Domains\Catalog\Models\Movie;
use App\Domains\Catalog\Models\Show;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\BufferedOutput;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Fixtures (byte-exact real source slices)
|--------------------------------------------------------------------------
| tests/Fixtures/Catalog/tmdb/movie_ids.json.gz — daily export incl. id 603
|   (The Matrix). Read only by the --fresh run's catalog:seed-movies leg.
| tests/Fixtures/Catalog/tmdb/movie.json — /movie/603 (imdb_id tt0133093);
|   tv.json — /tv/1399 (external_ids.imdb_id tt0944947).
| tests/Fixtures/Catalog/tvdb/* — login JWT, chained /updates feed, and
|   Breaking Bad's /series/434847/extended (_tvdb_id 81189, IMDB tt0903747).
| tests/Fixtures/Catalog/tvdb/series_page1.json + series_empty.json — the full
|   allSeries crawl (GET /series?page=0 then /series?page=1) that --fresh now
|   drives via catalog:seed-shows-tvdb, faked in fakeCatalogSyncFreshAndUpdates().
|
| Hand-authored (synthetic) bodies, for inputs no real capture supplies:
| — the one-result and empty-result /movie/changes and /tv/changes pages;
| — the EMPTY-gz ids export in fakeCatalogSync(), whose only job is to make a run
|   that still downloads the export fail on a behavioural assertion rather than
|   die as a stray request (stray requests are globally prevented). Nothing can
|   reach the catalog through it.
|
| A default catalog:sync dispatches catalog:sync-movies → catalog:sync-shows-tvdb →
| catalog:sync-episodes-tvdb → catalog:sync-shows-tmdb, and never touches the ids
| export: the incremental movies leg reads the changes feed alone. Under --fresh the
| movies step swaps to the full export seed and the TVDB show step to the full crawl —
| catalog:seed-movies --fresh → catalog:seed-shows-tvdb → catalog:sync-episodes-tvdb →
| catalog:sync-shows-tmdb --fresh.
*/

/**
 * Fake every host a default catalog:sync touches with happy-path fixtures: the
 * TMDB changes feeds, the shared TMDB API (The Matrix for id 603, Game of Thrones
 * for id 1399, 404 else), and TheTVDB's /updates path (login JWT, the chained
 * updates feed, Breaking Bad's extended payload for the one discovered recordId
 * 434847).
 *
 * The ids export is stubbed EMPTY rather than omitted: a default run must never
 * download it, and an empty body makes a run that still does fail on its
 * behavioural assertion instead of dying as a stray request.
 */
function fakeCatalogSync(): void
{
    Http::fake([
        '*movie_ids*' => Http::response(gzencode('')),
        // The changes feed is the incremental movies leg's only source, so The Matrix
        // (603) has to arrive through it. The shows leg's feed stays empty, driving
        // its update phase through the success path (no swallowed exception, no stray
        // stack trace). Both are listed before the generic detail stub since they live
        // on the same host.
        '*/movie/changes*' => Http::response('{"results":[{"id":603}],"page":1,"total_pages":1,"total_results":1}'),
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
 * The full-seed fake for a --fresh run: the real ids export the catalog:seed-movies
 * leg scans, TheTVDB's login, the allSeries crawl (/series?page=0|1), and Breaking
 * Bad's extended payload for recordId 434847 (404 for every other crawled id). The
 * /updates feed is faked too, purely as a should-not-fire guard — under --fresh the
 * TVDB step is the crawl, so the test proves /updates is never requested. The
 * specific /series?page patterns precede the wildcard extended-series pattern so
 * Http::fake matches them correctly.
 */
function fakeCatalogSyncFreshAndUpdates(): void
{
    Http::fake([
        '*movie_ids*' => Http::response(fixtureBytes('Catalog/tmdb/movie_ids.json.gz')),
        // /movie/changes is a should-not-fire guard here: under --fresh the movies
        // step is the export seed, which reads no changes feed. /tv/changes is real —
        // the TMDB show sync still runs — and its empty-results page drives that
        // update phase through its success path. Both are listed before the generic
        // detail stub since they live on the same host.
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

/**
 * Run the orchestrator against a buffer we own and hand back everything it wrote,
 * children included.
 *
 * `Artisan::output()` reads back empty here: catalog:sync forwards `$this->output`
 * into its own `Artisan::call` per child, which leaves the kernel's last output an
 * `OutputStyle` — no `fetch()`, so the read yields ''. Passing our own buffer keeps
 * the whole run in one readable string, which is what ordering assertions need.
 *
 * @param  array<string, bool>  $arguments
 */
function catalogSyncOutput(array $arguments = []): string
{
    Artisan::call('catalog:sync', $arguments, $buffer = new BufferedOutput);

    return $buffer->fetch();
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
        // The ids export is stubbed empty, so the changes feed is the only place 603
        // can have come from: the incremental leg discovered and inserted The Matrix.
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
        // registered ahead of the happy-path helper overrides only the changes feed —
        // the incremental movies leg's sole source, and so its only way to die.
        Http::fake(['*/movie/changes*' => Http::response('', 500)]);
        fakeCatalogSync();

        // Act & Assert
        $this->artisan('catalog:sync')->assertExitCode(Command::FAILURE);

        // Assert
        // The dead feed takes catalog:sync-movies down with it, but the orchestrator
        // reports the throwable and keeps dispatching — so the TVDB show still lands.
        Exceptions::assertReported(TmdbRequestFailed::class);
        expect(Show::where('_tvdb_id', 81189)->first())->not->toBeNull();
    });

    it('names the failed leg on the console and closes with a summary', function (): void {
        // Arrange
        Sleep::fake();
        Exceptions::fake();
        Http::fake(['*/movie/changes*' => Http::response('', 500)]);
        fakeCatalogSync();

        // Act & Assert
        // The defect this pins: a leg that lost its window was report()ed and nothing
        // was printed, so a run that lost a leg read as a clean exit at the prompt.
        // The leg catches the feed failure itself and closes on a non-zero exit code,
        // so the orchestrator has to relay that code by name — otherwise the only
        // trace of a half-covered window is buried in the child's own output.
        $this->artisan('catalog:sync')
            ->expectsOutputToContain('catalog:sync-movies failed with exit code 1')
            ->expectsOutputToContain('Failed commands: catalog:sync-movies')
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
    it('under --fresh crawls the full TVDB seed, re-seeds every exported movie and forwards --fresh to the TMDB show sync', function (): void {
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
        // type=episodes dispatch itself is asserted elsewhere. Both TMDB hydrations
        // are discriminators for a forwarded --fresh: 603 and 1399 are arranged as
        // already-synced rows, which a plain export seed filters out and a plain show
        // sync skips, so each request can only come from a leg that got the flag.
        Http::assertSent(fn (Request $request): bool => Str::contains($request->url(), '/series?page'));
        Http::assertNotSent(fn (Request $request): bool => Str::contains(urldecode((string) $request->url()), 'type=series')
            && ! Str::contains($request->url(), 'page='));
        Http::assertSent(fn (Request $request): bool => Str::contains($request->url(), '/movie/603'));
        Http::assertSent(fn (Request $request): bool => Str::contains($request->url(), '/tv/1399'));
    });

    it('on a default run uses the TVDB updates feed and forwards no --fresh to the TMDB show sync', function (): void {
        // Arrange
        fakeCatalogSync();
        Show::factory()->withTmdb()->create(['_tmdb_id' => 1399]);

        // Act
        $this->artisan('catalog:sync');

        // Assert
        // No --fresh means the updates feed, never the crawl, and the already-synced
        // 1399 row is skipped so its hydration never fires. The movies leg is no
        // longer part of this pair: catalog:sync-movies carries no --fresh option at
        // all, and refreshes every id its changes feed reports whether the catalog
        // holds it or not — what the default run must NOT do there is fetch the ids
        // export, asserted in "catalog:sync movies-leg routing".
        Http::assertSent(fn (Request $request): bool => Str::contains($request->url(), '/updates'));
        Http::assertNotSent(fn (Request $request): bool => Str::contains($request->url(), '/series?page'));
        Http::assertNotSent(fn (Request $request): bool => Str::contains($request->url(), '/tv/1399'));
    });
});

describe('catalog:sync movies-leg routing', function (): void {
    it('downloads no ids export on a default run, reading the changes feed instead', function (): void {
        // Arrange
        fakeCatalogSync();

        // Act
        $this->artisan('catalog:sync');

        // Assert
        // Both halves matter: the twice-daily sync must not re-scan a ~1M-row export,
        // and the feed assertion is what stops a run that did nothing at all from
        // passing the first half by default.
        Http::assertNotSent(fn (Request $request): bool => Str::contains($request->url(), 'movie_ids'));
        Http::assertSent(fn (Request $request): bool => Str::contains($request->url(), '/movie/changes'));
    });

    it('dispatches catalog:seed-movies under --fresh, never catalog:sync-movies', function (): void {
        // Arrange
        fakeCatalogSyncFreshAndUpdates();

        // Act
        $output = catalogSyncOutput(['--fresh' => true]);

        // Assert
        // The announcement line names which leg was dispatched; the export request is
        // the independent behavioural half, since only the seed leg downloads it.
        // Together they pin the switch without reaching for the dispatcher itself.
        expect($output)->toContain('Running catalog:seed-movies…');
        expect($output)->not->toContain('Running catalog:sync-movies…');
        Http::assertSent(fn (Request $request): bool => Str::contains($request->url(), 'movie_ids'));
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

describe('catalog:sync progress output', function (): void {
    it('announces each child command before running it', function (): void {
        // Arrange
        fakeCatalogSync();

        // Act
        $output = catalogSyncOutput();

        // Assert
        // Offsets, not substrings: the point is that the announcement lands *before*
        // the child's own first phase line, which containment alone cannot express.
        // strpos returns false while the line is missing, and `false < int` is true
        // under PHP's loose comparison — so the presence check has to be its own
        // assertion rather than being folded into the ordering one.
        $announcedAt = strpos($output, 'Running catalog:sync-movies…');
        $firstPhaseAt = strpos($output, 'Syncing changed movies…');

        expect($announcedAt)->toBeInt();
        expect($announcedAt)->toBeLessThan($firstPhaseAt);
        expect($output)->toContain('Running catalog:sync-shows-tvdb…')
            ->toContain('Running catalog:sync-episodes-tvdb…')
            ->toContain('Running catalog:sync-shows-tmdb…');
    });

    it('names the failed child in a closing line', function (): void {
        // Arrange
        Sleep::fake();
        Exceptions::fake();
        // Http::fake merges stubs and the first registered match wins, so this 500
        // registered ahead of the happy-path helper overrides only the changes feed —
        // the incremental movies leg's sole source, and so its only way to die.
        Http::fake(['*/movie/changes*' => Http::response('', 500)]);
        fakeCatalogSync();

        // Act
        $output = catalogSyncOutput();

        // Assert
        // A run that keeps dispatching past a dead child otherwise names the guilty
        // one only by accident, buried in the interleaved wall of child output.
        expect($output)->toContain("Failed commands: catalog:sync-movies\n");
    });

    it('closes the run with its own Done.', function (): void {
        // Arrange
        fakeCatalogSync();

        // Act
        $output = catalogSyncOutput();

        // Assert
        // The last child (catalog:sync-shows-tmdb) signs off with its own `Done.`, so
        // a second one on the final line is what proves catalog:sync closed the run
        // rather than just trailing off after the last child.
        expect($output)->toEndWith("Done.\nDone.\n");
    });
});
