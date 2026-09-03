<?php

declare(strict_types=1);

use App\Domains\Catalog\Enums\SyncFeed;
use App\Domains\Catalog\Models\Movie;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Fixtures (byte-exact real TMDB slices)
|--------------------------------------------------------------------------
| tests/Fixtures/Catalog/tmdb/movie_ids.json.gz — gz JSONL daily export; the
|   kept (non-adult/non-softcore) rows include id 603 (The Matrix), alongside the
|   other real export ids.
| tests/Fixtures/Catalog/tmdb/movie.json — the /movie/603 detail response
|   (The Matrix, imdb_id tt0133093) with appended images.{posters,backdrops,logos}.
|
| The export host and the TMDB API host are distinct, and stray requests are
| globally prevented, so both hosts are faked. The API closure serves The Matrix
| only for id 603 and 404s every other exported id.
|
| Hand-authored (synthetic) bodies, for inputs no real capture can supply:
| — the arbitrary-ids export built by fakeTmdbSeedIdsExport() — `{"id":N}` JSONL
|   rows over ids chosen so a synced row and an unsynced sibling sit in the same
|   export, and sized to straddle the probe buffer and the hydrate batch (whose
|   sizes differ), neither of which any committed capture provides — plus a minimal
|   `{"id":N,"title":"Movie N"}` detail body per requested id, images omitted so
|   the runs stay fast.
| — the empty `/movie/changes` results page. This command must never read the
|   changes feed, so the stub exists ONLY so a leg that wrongly requests it fails
|   on the "no changes feed" assertion rather than dying as a stray request.
*/

/**
 * $exportSink, when passed, captures the temp path the export download sinks to,
 * so a caller can assert on that exact file instead of globbing the shared
 * system temp dir (where sibling suites' files come and go mid-assertion).
 */
function fakeTmdbMovieSeed(?string &$exportSink = null): void
{
    Http::fake([
        '*movie_ids*' => function (Request $request, array $options) use (&$exportSink) {
            $exportSink = $options['sink'];

            return Http::response(fixtureBytes('Catalog/tmdb/movie_ids.json.gz'));
        },
        // Listed before the generic detail stub since it lives on the same host.
        '*/movie/changes*' => Http::response('{"results":[],"page":1,"total_pages":1,"total_results":0}'),
        '*api.themoviedb.org*' => fn (Request $request) => Str::contains($request->url(), '/movie/603')
            ? Http::response(fixtureBytes('Catalog/tmdb/movie.json'))
            : Http::response('', 404),
    ]);
}

/**
 * Fakes an export of exactly the given ids, each resolving to a minimal detail
 * body. $onDetail observes every detail request, for the interleaving timeline.
 *
 * @param  list<int>  $ids
 */
function fakeTmdbSeedIdsExport(array $ids, ?Closure $onDetail = null): void
{
    $lines = array_map(fn (int $id): string => json_encode(['id' => $id]), $ids);

    Http::fake([
        '*movie_ids*' => Http::response(gzencode(implode("\n", $lines))),
        '*/movie/changes*' => Http::response('{"results":[],"page":1,"total_pages":1,"total_results":0}'),
        '*api.themoviedb.org*' => function (Request $request) use ($onDetail) {
            preg_match('#/movie/(\d+)#', (string) $request->url(), $matches);
            $id = (int) ($matches[1] ?? 0);

            if ($onDetail instanceof Closure) {
                $onDetail($id);
            }

            return Http::response(json_encode(['id' => $id, 'title' => "Movie {$id}"]));
        },
    ]);
}

/**
 * The probe statements captured in the query log.
 *
 * @return Collection<int, array{query: string, bindings: array<int, mixed>}>
 */
function loggedSyncedProbes(): Collection
{
    return loggedStatements(isSyncedProbe(...));
}

describe('catalog:seed-movies export scan', function (): void {
    it('hydrates an exported id the catalog does not hold', function (): void {
        // Arrange
        fakeTmdbMovieSeed();

        // Act
        $this->artisan('catalog:seed-movies');

        // Assert
        $matrix = Movie::where('_tmdb_id', 603)->first();
        expect($matrix)->not->toBeNull();
        expect($matrix->_tmdb_title)->toBe('The Matrix');
    });

    it('skips an already-synced exported id on a default run', function (): void {
        // Arrange
        Movie::factory()->create(['_tmdb_id' => 8001, 'tmdb_synced_at' => now()]);
        fakeTmdbSeedIdsExport([8001, 8002]);

        // Act
        $this->artisan('catalog:seed-movies');

        // Assert
        // Both halves in one test: asserting the absence alone would pass on a run
        // that requested nothing at all.
        Http::assertNotSent(fn (Request $request): bool => Str::endsWith((string) parse_url($request->url(), PHP_URL_PATH), '/movie/8001'));
        Http::assertSent(fn (Request $request): bool => Str::endsWith((string) parse_url($request->url(), PHP_URL_PATH), '/movie/8002'));
    });

    it('re-hydrates an already-synced exported id under --fresh', function (): void {
        // Arrange
        Movie::factory()->create(['_tmdb_id' => 8001, 'tmdb_synced_at' => now()]);
        fakeTmdbSeedIdsExport([8001]);

        // Act
        $this->artisan('catalog:seed-movies', ['--fresh' => true]);

        // Assert
        Http::assertSent(fn (Request $request): bool => Str::endsWith((string) parse_url($request->url(), PHP_URL_PATH), '/movie/8001'));
    });

    it('deletes the export temp file and exits SUCCESS', function (): void {
        // Capturing the sink path pins the assertion to THIS run's temp file; globbing
        // the shared system temp dir would also see files other processes create and
        // remove mid-run. The toBeString() guard proves the export really downloaded,
        // so "no leftover file" can't pass vacuously on a run that never sank one.
        // Arrange
        $sinkPath = null;
        fakeTmdbMovieSeed($sinkPath);

        // Act
        $this->artisan('catalog:seed-movies')->assertExitCode(0);

        // Assert
        expect($sinkPath)->toBeString();
        expect(file_exists($sinkPath))->toBeFalse();
    });

    it('reads no changes feed', function (): void {
        // Arrange
        fakeTmdbMovieSeed();

        // Act
        $this->artisan('catalog:seed-movies');

        // Assert
        // Both halves in one test: the absence alone would pass on a run that made no
        // request at all, so the export download is asserted alongside it.
        Http::assertNotSent(fn (Request $request): bool => Str::contains($request->url(), '/movie/changes'));
        Http::assertSent(fn (Request $request): bool => Str::contains($request->url(), 'movie_ids'));
    });

    it('advances the movies marker on a clean run', function (): void {
        // Arrange
        Cache::flush();
        Date::setTestNow('2026-07-16 12:00:00');
        fakeTmdbMovieSeed();

        // Act
        $this->artisan('catalog:seed-movies');

        // Assert
        expect(Cache::get(SyncFeed::TmdbMovies->cacheKey()))->toBe(now()->toIso8601String());
    });
});

describe('catalog:seed-movies export probing and batching', function (): void {
    it('probes synced ids per buffer, never the whole catalog', function (): void {
        // Arrange
        // 1001 exported ids straddle the 1000-id probe buffer, so a bounded
        // implementation probes twice — once per buffer, each probe carrying its
        // buffer's ids as bindings. Reading the whole synced catalog up front is a
        // single, binding-less statement instead.
        fakeTmdbSeedIdsExport(range(1, 1001));
        DB::enableQueryLog();

        // Act
        $this->artisan('catalog:seed-movies');

        // Assert
        $bindingCounts = loggedSyncedProbes()->map(fn (array $entry): int => count($entry['bindings']));
        expect($bindingCounts->count())->toBeGreaterThanOrEqual(2);
        expect($bindingCounts->min())->toBeGreaterThanOrEqual(1);
        expect($bindingCounts->max())->toBeLessThanOrEqual(1000);
    });

    it('probes lazily — the second buffer is probed only after the first batch hydrated', function (): void {
        // Arrange
        // One shared timeline records both sides of the interleave, so their ORDER is
        // observable: a materialize-then-filter pass emits every probe before the
        // first hydrate, while a streaming buffer probes the second buffer only after
        // the first one's ids have been hydrated.
        $timeline = [];
        DB::listen(function (QueryExecuted $query) use (&$timeline): void {
            if (isSyncedProbe($query->sql)) {
                $timeline[] = 'probe';
            }
        });
        fakeTmdbSeedIdsExport(range(1, 1001), function (int $id) use (&$timeline): void {
            $timeline[] = 'hydrate';
        });

        // Act
        $this->artisan('catalog:seed-movies');

        // Assert
        $probes = array_keys($timeline, 'probe', true);
        $hydrates = array_keys($timeline, 'hydrate', true);
        expect($probes)->not->toBeEmpty();
        expect($hydrates)->not->toBeEmpty();
        // The last probe (the second buffer's) must land after the first hydrate.
        // Reading the whole synced catalog up front puts every probe before every
        // hydrate, so the two sequences never interleave.
        expect(max($probes))->toBeGreaterThan(min($hydrates));
    });

    it('hydrates the trailing partial buffer', function (): void {
        // Arrange
        // Id 1001 is alone in the second buffer: a buffered probe that only flushes
        // on a full buffer would drop it.
        fakeTmdbSeedIdsExport(range(1, 1001));

        // Act
        $this->artisan('catalog:seed-movies');

        // Assert
        expect(Movie::where('_tmdb_id', 1001)->exists())->toBeTrue();
    });

    it('upserts the export scan in HYDRATE_SIZE batches', function (): void {
        // Arrange
        // 501 hydratable ids all fit inside ONE probe buffer, so the number of upserts
        // can only be decided by the hydrate batch size: 250 + 250 + 1 = three writes.
        // A hydrate batch as wide as the buffer collapses them into a single upsert
        // holding all 501 decoded payloads at once.
        fakeTmdbSeedIdsExport(range(1, 501));
        DB::enableQueryLog();

        // Act
        $this->artisan('catalog:seed-movies');

        // Assert
        expect(loggedInsertsInto('movies')->count())->toBe(3);
        expect(Movie::count())->toBe(501);
    });

    it('sizes the probe and the hydrate independently', function (): void {
        // Arrange
        // 1001 ids straddle both boundaries at once: two probe buffers (1000 + 1) and
        // five hydrate batches (4 × 250 + 1). No single shared constant can produce
        // both counts, so this pins the two sizes as genuinely separate knobs.
        fakeTmdbSeedIdsExport(range(1, 1001));
        DB::enableQueryLog();

        // Act
        $this->artisan('catalog:seed-movies');

        // Assert
        expect(loggedSyncedProbes()->count())->toBe(2);
        expect(loggedInsertsInto('movies')->count())->toBe(5);
    });

    it('issues no probe query with --fresh', function (): void {
        // Arrange
        fakeTmdbMovieSeed();
        DB::enableQueryLog();

        // Act
        $this->artisan('catalog:seed-movies', ['--fresh' => true]);

        // Assert
        // This absence is clean only because the leg reads no changes feed either —
        // that phase's whereNotNull('tmdb_synced_at') intersection probes the same
        // column, and would make this mean something weaker.
        expect(loggedSyncedProbes()->pluck('query')->all())->toBe([]);
    });
});

describe('catalog:seed-movies scan heartbeat', function (): void {
    it('beats every 10000th export row scanned on a run that upserts nothing', function (): void {
        // Arrange
        // The seeded-production case: every exported id is already synced, so the scan
        // hydrates and upserts NOTHING and the upsert heartbeat can never fire — a
        // scan-unit beat is the only thing that can prove the run is alive.
        // Bulk inserts, not factories: 20k factory saves each round-trip the Searchable
        // trait. They also leave `updated_at` NULL on purpose — the leg ends with a
        // deferred reindex over `updated_at >= <run start>`, and rows saved inside the
        // run's own start second would otherwise be swept 20k deep through the engine.
        $ids = range(1, 20_000);
        $syncedAt = now()->toDateTimeString();
        foreach (array_chunk($ids, 5_000) as $chunk) {
            Movie::insert(array_map(
                static fn (int $id): array => ['_tmdb_id' => $id, 'tmdb_synced_at' => $syncedAt],
                $chunk,
            ));
        }
        fakeTmdbSeedIdsExport($ids);

        // Act
        Artisan::call('catalog:seed-movies');

        // Assert
        // The row count pins the scenario itself: it can only still read 20000 if the
        // run hydrated nothing, so these beats can't be passing off upsert work as scan.
        $output = Artisan::output();
        expect($output)->toContain('  [scan 10000]');
        expect($output)->toContain('  [scan 20000]');
        expect(Movie::count())->toBe(20_000);
    });
});
