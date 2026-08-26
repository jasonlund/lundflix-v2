<?php

declare(strict_types=1);

use App\Domains\Catalog\Enums\SyncFeed;
use App\Domains\Catalog\Exceptions\TmdbRequestFailed;
use App\Domains\Catalog\Models\Movie;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\Console\Exception\InvalidOptionException;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Fixtures (byte-exact real TMDB slices)
|--------------------------------------------------------------------------
| tests/Fixtures/Catalog/tmdb/movie_ids.json.gz — gz JSONL daily export; the
|   kept (non-adult/non-softcore) rows include id 603 (The Matrix), appended for
|   this ingestor slice, alongside the other real export ids.
| tests/Fixtures/Catalog/tmdb/movie.json — the /movie/603 detail response
|   (The Matrix, imdb_id tt0133093) with appended images.{posters,backdrops,logos}.
|
| The export host and the TMDB API host are distinct, and stray requests are
| globally prevented, so both hosts are faked. The API closure serves The Matrix
| only for id 603 and 404s every other exported id, exercising the pooled-miss path.
|
| Hand-authored (synthetic) bodies, for inputs no real capture can supply:
| — the arbitrary-ids export built by fakeTmdbIdsExport() — `{"id":N}` JSONL rows,
|   sized to straddle the probe buffer and the hydrate batch (whose sizes differ),
|   which no committed capture provides — plus a minimal
|   `{"id":N,"title":"Movie N"}` detail body per requested id, images omitted so
|   the volume runs stay fast.
| — the empty `/movie/changes` results page, which drives the update phase through
|   its success path so it issues no `tmdb_synced_at` query of its own.
| — a single-page `/movie/changes` body listing 251 arbitrary ids — one over the
|   hydrate batch, so the changes phase's own batching is observable — which no
|   committed capture provides; its ids are re-hydrated through the same minimal
|   `{"id":N,"title":"Movie N"}` detail body.
| — a single-page `/movie/changes` body listing 1001 arbitrary ids we hold none
|   of — one over the probe buffer, so a trailing partial slice is observable —
|   which no committed capture provides. Nothing hydrates off it, so no detail
|   body pairs with it.
*/

/**
 * $exportSink, when passed, captures the temp path the export download sinks to,
 * so a caller can assert on that exact file instead of globbing the shared
 * system temp dir (where sibling suites' files come and go mid-assertion).
 */
function fakeTmdbSync(?string &$exportSink = null): void
{
    Http::fake([
        '*movie_ids*' => function (Request $request, array $options) use (&$exportSink) {
            $exportSink = $options['sink'];

            return Http::response(fixtureBytes('Catalog/tmdb/movie_ids.json.gz'));
        },
        // A default run always hits the changes feed after the insert phase; an
        // empty-results page drives the update phase through its success path
        // (no swallowed exception, no stray stack trace). Listed before the
        // generic detail stub since it lives on the same host.
        '*/movie/changes*' => Http::response('{"results":[],"page":1,"total_pages":1,"total_results":0}'),
        '*api.themoviedb.org*' => fn (Request $request) => Str::contains($request->url(), '/movie/603')
            ? Http::response(fixtureBytes('Catalog/tmdb/movie.json'))
            : Http::response('', 404),
    ]);
}

/*
| Fakes the three hosts the update-changed phase touches. The export is empty so
| the insert-new phase is a no-op and can't interfere with the update phase.
| movie_changes_page1.json declares total_pages:2, so the client pages through to
| page 2 (movie_changes_page2.json) — both are hand-authored representative
| fixtures approximating the /movie/changes wire format, not verbatim live
| captures. The
| changes feed lives on the TMDB API host too, so its stub is listed BEFORE the
| generic detail stub. The Matrix detail body is re-keyed onto id 345 (the only
| synthetic touch, an accepted pattern here) so the detail-upsert — which keys on
| the payload's id — lands on the existing _tmdb_id 345 row; every other detail id
| 404s.
*/
function fakeTmdbUpdateSync(): void
{
    $decoded = json_decode(fixtureBytes('Catalog/tmdb/movie.json'), true);
    $decoded['id'] = 345;
    $detailBody = json_encode($decoded);

    Http::fake([
        '*movie_ids*' => Http::response(gzencode('')),
        '*/movie/changes*' => function (Request $request) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return (int) ($query['page'] ?? 1) === 2
                ? Http::response(fixtureBytes('Catalog/tmdb/movie_changes_page2.json'))
                : Http::response(fixtureBytes('Catalog/tmdb/movie_changes_page1.json'));
        },
        '*api.themoviedb.org*' => fn (Request $request) => Str::endsWith((string) parse_url($request->url(), PHP_URL_PATH), '/movie/345')
            ? Http::response($detailBody)
            : Http::response('', 404),
    ]);
}

/**
 * Fakes an export of exactly the given ids, each resolving to a minimal detail
 * body. $onDetail observes every detail request, for the interleaving timeline.
 *
 * The empty /movie/changes page is stubbed explicitly (before the generic detail
 * stub, same host): the update phase queries tmdb_synced_at too, and a 404 there
 * would both throw and pollute the probe assertions.
 *
 * @param  list<int>  $ids
 */
function fakeTmdbIdsExport(array $ids, ?Closure $onDetail = null): void
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

/*
| The mid-stream variant of fakeTmdbUpdateSync(): page 1 of /movie/changes is
| served verbatim (movie_changes_page1.json, which declares total_pages:2), then
| page TWO 404s — so the fatal TmdbRequestFailed lands only AFTER the feed has
| already yielded page 1's ids, the failure ordering a lazily-paged feed
| introduces and a page-ONE-404 fake can never produce. The export is empty, so
| the insert phase is a no-op and every observed effect can only come from the
| update phase. Every detail 404s: this fake exists to observe what the update
| phase does with a half-read feed, not to hydrate.
*/
function fakeTmdbMidStreamChangesFailure(): void
{
    Http::fake([
        '*movie_ids*' => Http::response(gzencode('')),
        '*/movie/changes*' => function (Request $request) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return (int) ($query['page'] ?? 1) === 2
                ? Http::response('', 404)
                : Http::response(fixtureBytes('Catalog/tmdb/movie_changes_page1.json'));
        },
        '*api.themoviedb.org*' => Http::response('', 404),
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

/**
 * The narrow selects against `movies`. The already-synced probe is excluded — it
 * plucks a single column too and would otherwise satisfy the shape on its own.
 *
 * @return Collection<int, array{query: string, bindings: array<int, mixed>}>
 */
function narrowMovieSelects(): Collection
{
    return narrowSelects('movies', fn (string $sql): bool => ! isSyncedProbe($sql));
}

/*
| Shared by the marker-derived window tests: asserts the /movie/changes request
| carried the given start/end dates, ignoring every non-changes request.
*/
function assertRequestedChangesWindow(string $start, string $end): void
{
    Http::assertSent(function (Request $request) use ($start, $end): bool {
        if (! Str::contains($request->url(), '/movie/changes')) {
            return false;
        }
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return ($query['start_date'] ?? null) === $start
            && ($query['end_date'] ?? null) === $end;
    });
}

describe('catalog:sync-movies insert-phase ingest', function (): void {
    it('persists hydrated movies with _tmdb_ columns', function (): void {
        // Arrange
        fakeTmdbSync();

        // Act
        $this->artisan('catalog:sync-movies');

        // Assert
        $matrix = Movie::where('_tmdb_id', 603)->first();
        expect($matrix)->not->toBeNull();
        expect($matrix->_tmdb_title)->toBe('The Matrix');
    });

    it('persists the hydrated movie images into media', function (): void {
        // Arrange
        fakeTmdbSync();

        // Act
        $this->artisan('catalog:sync-movies');

        // Assert
        $matrix = Movie::where('_tmdb_id', 603)->firstOrFail();
        expect($matrix->media()->where('is_active', true)->count())->toBeGreaterThan(0);
    });

    it('exits SUCCESS and deletes the export temp file', function (): void {
        // Capturing the sink path pins the assertion to THIS run's temp file; globbing
        // the shared system temp dir would also see files other processes create and
        // remove mid-run, reddening the test for reasons unrelated to a leak. The
        // toBeString() guard proves the export really downloaded, so "no leftover file"
        // can't pass vacuously on a run that never sank one.
        // Arrange
        $sinkPath = null;
        fakeTmdbSync($sinkPath);

        // Act
        $this->artisan('catalog:sync-movies')->assertExitCode(0);

        // Assert
        expect($sinkPath)->toBeString();
        expect(file_exists($sinkPath))->toBeFalse();
    });

    it('writes _imdb_id from the payload on the upserted _tmdb_id row', function (): void {
        // Arrange
        fakeTmdbSync();

        // Act
        $this->artisan('catalog:sync-movies');

        // Assert
        $matrix = Movie::where('_tmdb_id', 603)->first();
        expect($matrix)->not->toBeNull();
        expect($matrix->_imdb_id)->toBe('tt0133093');
    });

    it('rejects the removed --limit option', function (): void {
        // Arrange
        // no state to arrange — option binding fails before the command boots

        // Act & Assert
        // `run()` must be called INSIDE the closure: PendingCommand otherwise defers
        // execution to its destructor and the throw escapes expect() entirely.
        expect(fn () => $this->artisan('catalog:sync-movies', ['--limit' => 1])->run())
            ->toThrow(InvalidOptionException::class);
    });
});

describe('catalog:sync-movies synced-id probing and batching', function (): void {
    it('skips an already-synced movie on a default run', function (): void {
        // Arrange
        Movie::factory()->create(['_tmdb_id' => 603, 'tmdb_synced_at' => now()]);
        fakeTmdbSync();

        // Act
        $this->artisan('catalog:sync-movies');

        // Assert
        Http::assertNotSent(fn (Request $request): bool => Str::contains($request->url(), '/movie/603'));
    });

    it('reprocesses an already-synced movie with --fresh', function (): void {
        // Arrange
        Movie::factory()->create(['_tmdb_id' => 603, 'tmdb_synced_at' => now()]);
        fakeTmdbSync();

        // Act
        $this->artisan('catalog:sync-movies', ['--fresh' => true]);

        // Assert
        Http::assertSent(fn (Request $request): bool => Str::contains($request->url(), '/movie/603'));
    });

    it('probes synced ids per buffer, never the whole catalog', function (): void {
        // Arrange
        // 1001 exported ids straddle the 1000-id probe buffer, so a bounded
        // implementation probes twice — once per buffer, each probe carrying its
        // buffer's ids as bindings. Reading the whole synced catalog up front is a
        // single, binding-less statement instead.
        fakeTmdbIdsExport(range(1, 1001));
        DB::enableQueryLog();

        // Act
        $this->artisan('catalog:sync-movies');

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
        fakeTmdbIdsExport(range(1, 1001), function (int $id) use (&$timeline): void {
            $timeline[] = 'hydrate';
        });

        // Act
        $this->artisan('catalog:sync-movies');

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

    it('yields only the unsynced rows inside a mixed buffer', function (): void {
        // Arrange
        Movie::factory()->create(['_tmdb_id' => 8001, 'tmdb_synced_at' => now()]);
        fakeTmdbIdsExport([8001, 8002, 8003]);

        // Act
        $this->artisan('catalog:sync-movies');

        // Assert
        Http::assertNotSent(fn (Request $request): bool => Str::endsWith((string) parse_url($request->url(), PHP_URL_PATH), '/movie/8001'));
        expect(Movie::where('_tmdb_id', 8002)->exists())->toBeTrue();
        expect(Movie::where('_tmdb_id', 8003)->exists())->toBeTrue();
    });

    it('hydrates the trailing partial buffer', function (): void {
        // Arrange
        // Id 1001 is alone in the second buffer: a buffered probe that only flushes
        // on a full buffer would drop it.
        fakeTmdbIdsExport(range(1, 1001));

        // Act
        $this->artisan('catalog:sync-movies');

        // Assert
        expect(Movie::where('_tmdb_id', 1001)->exists())->toBeTrue();
    });

    it('upserts the insert phase in HYDRATE_SIZE batches', function (): void {
        // Arrange
        // 501 hydratable ids all fit inside ONE probe buffer, so the number of upserts
        // can only be decided by the hydrate batch size: 250 + 250 + 1 = three writes.
        // A hydrate batch as wide as the buffer collapses them into a single upsert
        // holding all 501 decoded payloads at once.
        fakeTmdbIdsExport(range(1, 501));
        DB::enableQueryLog();

        // Act
        $this->artisan('catalog:sync-movies');

        // Assert
        expect(loggedInsertsInto('movies')->count())->toBe(3);
        expect(Movie::count())->toBe(501);
    });

    it('sizes the probe and the hydrate independently', function (): void {
        // Arrange
        // 1001 ids straddle both boundaries at once: two probe buffers (1000 + 1) and
        // five hydrate batches (4 × 250 + 1). No single shared constant can produce
        // both counts, so this pins the two sizes as genuinely separate knobs.
        fakeTmdbIdsExport(range(1, 1001));
        DB::enableQueryLog();

        // Act
        $this->artisan('catalog:sync-movies');

        // Assert
        expect(loggedSyncedProbes()->count())->toBe(2);
        expect(loggedInsertsInto('movies')->count())->toBe(5);
    });

    it('selects only id and _tmdb_id when resolving upserted movies for images', function (): void {
        // Arrange
        fakeTmdbSync();
        DB::enableQueryLog();

        // Act
        $this->artisan('catalog:sync-movies');

        // Assert
        // Presence, not absence: the wide Scout select always shares this log, so the
        // only observable proof is that a narrow one was issued as well.
        expect(narrowMovieSelects()->count())->toBeGreaterThanOrEqual(1);
    });

    it('issues no probe query with --fresh', function (): void {
        // Arrange
        fakeTmdbSync();
        DB::enableQueryLog();

        // Act
        $this->artisan('catalog:sync-movies', ['--fresh' => true]);

        // Assert
        // This absence is only clean because --fresh ALSO skips the changes phase,
        // whose whereNotNull('tmdb_synced_at') intersection probes the same column.
        // Move that gate and this stops meaning "the kept-rows stream never probed".
        expect(loggedSyncedProbes()->pluck('query')->all())->toBe([]);
    });
});

describe('catalog:sync-movies heartbeat and batch failures', function (): void {
    it('prints a phase-labeled heartbeat every 1000th hydrated title', function (): void {
        // Arrange
        // 1000 hydratable ids so the running count reaches the every-1000th boundary.
        // A minimal per-id payload (no images) keeps this volume test fast — the
        // heartbeat only needs a title; ingest correctness is covered by other tests.
        $lines = array_map(fn (int $id): string => json_encode(['id' => $id]), range(1, 1000));
        $export = gzencode(implode("\n", $lines));
        Http::fake([
            '*movie_ids*' => Http::response($export),
            '*api.themoviedb.org*' => function (Request $request) {
                preg_match('#/movie/(\d+)#', (string) $request->url(), $m);
                $id = (int) ($m[1] ?? 0);

                return Http::response(json_encode(['id' => $id, 'title' => "Movie {$id}"]));
            },
        ]);

        // Act & Assert
        $this->artisan('catalog:sync-movies')->expectsOutputToContain('[movies 1000]');
    });

    it('continues to the next batch when one batch throws', function (): void {
        // Arrange
        Exceptions::fake();
        // Synthetic export body: a >1000-row export is a structural input a committed
        // real fixture can't practically provide — ids 1..1001 span five 250-id hydrate
        // batches, so the id that throws (1, in batch 1) and the id that must still land
        // (1001, alone in batch 5) sit in different batches.
        $lines = array_map(fn (int $id): string => json_encode(['id' => $id]), range(1, 1001));
        $export = gzencode(implode("\n", $lines));
        $matrix = fixtureBytes('Catalog/tmdb/movie.json');
        $decoded = json_decode($matrix, true);
        $decoded['id'] = 1001;
        $batchTwoBody = json_encode($decoded);
        Http::fake([
            '*movie_ids*' => Http::response($export),
            '*api.themoviedb.org*' => function (Request $request) use ($batchTwoBody) {
                $path = (string) parse_url($request->url(), PHP_URL_PATH);

                return match (true) {
                    Str::endsWith($path, '/movie/1001') => Http::response($batchTwoBody),
                    // One batch-1 id 500s persistently; TMDB aggregates a persistent
                    // non-404 failure into a thrown TmdbRequestFailed, so batch 1 throws.
                    Str::endsWith($path, '/movie/1') => Http::response('', 500),
                    default => Http::response('', 404),
                };
            },
        ]);

        // Act
        // The command reports a failing batch's TmdbRequestFailed rather than
        // throwing, so it runs to completion and processes batch 2 regardless.
        $this->artisan('catalog:sync-movies');

        // Assert
        expect(Movie::where('_tmdb_id', 1001)->exists())->toBeTrue();
        Exceptions::assertReported(TmdbRequestFailed::class);
    });
});

describe('catalog:sync-movies heartbeat and elapsed phase lines', function (): void {
    it('beats every 10000th export row scanned on a run that upserts nothing', function (): void {
        // Arrange
        // The seeded-production case: every exported id is already synced, so the
        // insert phase hydrates and upserts NOTHING and the upsert heartbeat can never
        // fire — a scan-unit beat is the only thing that can prove the run is alive.
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
        fakeTmdbIdsExport($ids);

        // Act
        Artisan::call('catalog:sync-movies');

        // Assert
        // The row count pins the scenario itself: it can only still read 20000 if the
        // run hydrated nothing, so these beats can't be passing off upsert work as scan.
        $output = Artisan::output();
        expect($output)->toContain('  [scan 10000]');
        expect($output)->toContain('  [scan 20000]');
        expect(Movie::count())->toBe(20_000);
    });

    it('beats every 1000th changes-feed id probed', function (): void {
        // Arrange
        // 1001 changed ids we hold none of: the export is empty so the insert phase is
        // a no-op, and no id resolves locally so nothing hydrates — today the whole run
        // is silent. 1001 straddles the 1000-id probe buffer, so the trailing partial
        // slice must NOT beat a second time.
        $ids = range(4_000_000, 4_001_000);
        Http::fake([
            '*movie_ids*' => Http::response(gzencode('')),
            '*/movie/changes*' => Http::response(json_encode([
                'results' => array_map(static fn (int $id): array => ['id' => $id], $ids),
                'page' => 1,
                'total_pages' => 1,
                'total_results' => count($ids),
            ])),
            '*api.themoviedb.org*' => Http::response('', 404),
        ]);

        // Act & Assert
        $this->artisan('catalog:sync-movies')
            ->expectsOutputToContain('  [probe 1000]')
            ->doesntExpectOutputToContain('[probe 1001]');
    });

    it('prints the reindex phase line and the heartbeat', function (): void {
        // Arrange
        fakeTmdbSync();

        // Act & Assert
        $this->artisan('catalog:sync-movies')
            ->expectsOutputToContain('Reindexing movies…')
            ->expectsOutputToContain('  [reindex 1]');
    });

    it('closes every phase line with its elapsed seconds', function (): void {
        // The clock is frozen so `0s` is deterministic: on the real clock a phase that
        // happened to straddle a second boundary would print `1s` and flake.
        // Arrange
        Date::setTestNow('2026-07-16 12:00:00');
        fakeTmdbSync();

        // Act & Assert
        $this->artisan('catalog:sync-movies')
            ->expectsOutputToContain('Downloading movie-ids export… done in 0s')
            ->expectsOutputToContain('Syncing movies… done in 0s')
            ->expectsOutputToContain('Updating changed movies… done in 0s')
            ->expectsOutputToContain('Reindexing movies… done in 0s');
    });
});

describe('catalog:sync-movies changes-feed update phase', function (): void {
    it('refreshes an existing synced movie present in the changes feed', function (): void {
        // Arrange
        Movie::factory()->create(['_tmdb_id' => 345, 'tmdb_synced_at' => now(), '_tmdb_title' => 'Stale']);
        fakeTmdbUpdateSync();

        // Act
        $this->artisan('catalog:sync-movies');

        // Assert
        expect(Movie::where('_tmdb_id', 345)->first()->_tmdb_title)->toBe('The Matrix');
    });

    it('ignores a changed id not in the local catalog', function (): void {
        // Arrange
        Movie::factory()->create(['_tmdb_id' => 345, 'tmdb_synced_at' => now()]);
        fakeTmdbUpdateSync();

        // Act
        $this->artisan('catalog:sync-movies');

        // Assert
        Http::assertNotSent(fn (Request $request): bool => Str::contains($request->url(), '/movie/1648226'));
    });

    it('bounds a changes-phase hydrate batch to HYDRATE_SIZE', function (): void {
        // Arrange
        // 251 changed ids we already hold, one over the 250-id hydrate batch, so a
        // bounded changes phase writes 250 + 1 = two upserts. Sizing its hydrate loop
        // at the wider 1000 collapses them into one write holding 251 decoded payloads
        // at once. The export is empty, so the insert phase contributes no upsert of
        // its own and the count can only come from the changes phase. Distinct
        // `_tmdb_id`s per row: the column is uniquely indexed.
        $ids = range(9000, 9250);
        Movie::factory()
            ->count(count($ids))
            ->sequence(...array_map(fn (int $id): array => ['_tmdb_id' => $id], $ids))
            ->create(['tmdb_synced_at' => now()]);
        Http::fake([
            '*movie_ids*' => Http::response(gzencode('')),
            '*/movie/changes*' => Http::response(json_encode([
                'results' => array_map(fn (int $id): array => ['id' => $id], $ids),
                'page' => 1,
                'total_pages' => 1,
                'total_results' => count($ids),
            ])),
            '*api.themoviedb.org*' => function (Request $request) {
                preg_match('#/movie/(\d+)#', (string) $request->url(), $matches);
                $id = (int) ($matches[1] ?? 0);

                return Http::response(json_encode(['id' => $id, 'title' => "Movie {$id}"]));
            },
        ]);
        // Enabled LAST, after the factory rows: each of the 251 creates emits its own
        // `insert into movies`, which would swamp the two upserts under test.
        DB::enableQueryLog();

        // Act
        $this->artisan('catalog:sync-movies');

        // Assert
        expect(loggedInsertsInto('movies')->count())->toBe(2);
    });

    it('requests the changes window from the cached marker with a 6h overlap', function (): void {
        // Arrange
        Cache::flush();
        Date::setTestNow('2026-07-16 12:00:00');
        // Marker at 2026-07-14 04:00 — under 6h into its day, so the three candidate
        // window starts fall on three different calendar days, the only granularity
        // the assertion compares: marker − 6h → 2026-07-13; overlap dropped →
        // 2026-07-14; marker ignored for the 24h fallback → 2026-07-15.
        Cache::put(SyncFeed::TmdbMovies->cacheKey(), now()->subDays(2)->subHours(8)->toImmutable());
        Movie::factory()->create(['_tmdb_id' => 345, 'tmdb_synced_at' => now()]);
        fakeTmdbUpdateSync();

        // Act
        $this->artisan('catalog:sync-movies');

        // Assert
        assertRequestedChangesWindow('2026-07-13', '2026-07-16');
    });

    it('falls back to a 24h changes window when no marker is cached', function (): void {
        // Arrange
        Cache::flush();
        Date::setTestNow('2026-07-16 12:00:00');
        Movie::factory()->create(['_tmdb_id' => 345, 'tmdb_synced_at' => now()]);
        fakeTmdbUpdateSync();

        // Act
        $this->artisan('catalog:sync-movies');

        // Assert
        assertRequestedChangesWindow('2026-07-15', '2026-07-16');
    });

    it('skips the update phase with --fresh', function (): void {
        // Arrange
        Movie::factory()->create(['_tmdb_id' => 345, 'tmdb_synced_at' => now()]);
        fakeTmdbUpdateSync();

        // Act
        $this->artisan('catalog:sync-movies', ['--fresh' => true]);

        // Assert
        Http::assertNotSent(fn (Request $request): bool => Str::contains($request->url(), '/movie/changes'));
    });
});

describe('catalog:sync-movies video-flagged details', function (): void {
    it('does not persist a movie whose detail is flagged video true', function (): void {
        // Arrange
        $detail = json_decode(fixtureBytes('Catalog/tmdb/movie.json'), true);
        $detail['id'] = 700;
        $detail['video'] = true;
        Http::fake([
            '*movie_ids*' => Http::response(gzencode(json_encode(['id' => 700]))),
            '*/movie/changes*' => Http::response('{"results":[],"page":1,"total_pages":1,"total_results":0}'),
            '*api.themoviedb.org*' => fn (Request $request) => Str::endsWith((string) parse_url($request->url(), PHP_URL_PATH), '/movie/700')
                ? Http::response(json_encode($detail))
                : Http::response('', 404),
        ]);

        // Act
        $this->artisan('catalog:sync-movies');

        // Assert
        expect(Movie::where('_tmdb_id', 700)->exists())->toBeFalse();
    });

    it('persists a movie whose detail is not flagged video', function (): void {
        // Arrange
        $detail = json_decode(fixtureBytes('Catalog/tmdb/movie.json'), true);
        $detail['id'] = 701;
        Http::fake([
            '*movie_ids*' => Http::response(gzencode(json_encode(['id' => 701]))),
            '*/movie/changes*' => Http::response('{"results":[],"page":1,"total_pages":1,"total_results":0}'),
            '*api.themoviedb.org*' => fn (Request $request) => Str::endsWith((string) parse_url($request->url(), PHP_URL_PATH), '/movie/701')
                ? Http::response(json_encode($detail))
                : Http::response('', 404),
        ]);

        // Act
        $this->artisan('catalog:sync-movies');

        // Assert
        expect(Movie::where('_tmdb_id', 701)->exists())->toBeTrue();
        expect(Movie::where('_tmdb_id', 701)->first()->_tmdb_title)->toBe('The Matrix');
    });
});

describe('catalog:sync-movies changes-feed failure reporting', function (): void {
    it('reports a persistent changes-feed failure and still exits SUCCESS', function (): void {
        // Arrange
        Exceptions::fake();
        // Empty export → the insert phase is a no-op; the changes feed 404s on every
        // page, which TMDB raises as a fatal TmdbRequestFailed the update phase must
        // report rather than propagate.
        Http::fake([
            '*movie_ids*' => Http::response(gzencode('')),
            '*/movie/changes*' => Http::response('', 404),
            '*api.themoviedb.org*' => Http::response('', 404),
        ]);

        // Act
        $this->artisan('catalog:sync-movies')->assertExitCode(0);

        // Assert
        Exceptions::assertReported(TmdbRequestFailed::class);
    });

    it('reports a mid-stream changes-feed failure and still exits SUCCESS', function (): void {
        // Arrange
        Cache::flush();
        Exceptions::fake();
        fakeTmdbMidStreamChangesFailure();

        // Act
        $this->artisan('catalog:sync-movies')->assertExitCode(0);

        // Assert
        Exceptions::assertReported(TmdbRequestFailed::class);
        expect(Cache::get(SyncFeed::TmdbMovies->cacheKey()))->toBeNull();
    });
});

describe('catalog:sync-movies marker advancement', function (): void {
    it('advances the movies marker to run-start on a clean default run', function (): void {
        // Arrange
        Cache::flush();
        Date::setTestNow('2026-07-16 12:00:00');
        fakeTmdbSync();

        // Act
        $this->artisan('catalog:sync-movies');

        // Assert
        expect(Cache::get(SyncFeed::TmdbMovies->cacheKey())->equalTo(now()))->toBeTrue();
    });

    it('advances the movies marker on a --fresh run', function (): void {
        // Arrange
        Cache::flush();
        Date::setTestNow('2026-07-16 12:00:00');
        fakeTmdbSync();

        // Act
        $this->artisan('catalog:sync-movies', ['--fresh' => true]);

        // Assert
        expect(Cache::get(SyncFeed::TmdbMovies->cacheKey())->equalTo(now()))->toBeTrue();
    });

    it('does not advance the movies marker when an insert-phase batch fails', function (): void {
        // Arrange
        Cache::flush();
        Exceptions::fake();
        // The lone exported id 500s persistently; the pool aggregates it as a per-id
        // failure and drops the key from its result, so the insert phase reports failure.
        Http::fake([
            '*movie_ids*' => Http::response(gzencode(json_encode(['id' => 500]))),
            '*/movie/changes*' => Http::response('{"results":[],"page":1,"total_pages":1,"total_results":0}'),
            '*api.themoviedb.org*' => fn (Request $request) => Str::endsWith((string) parse_url($request->url(), PHP_URL_PATH), '/movie/500')
                ? Http::response('', 500)
                : Http::response('', 404),
        ]);

        // Act
        $this->artisan('catalog:sync-movies');

        // Assert
        expect(Cache::get(SyncFeed::TmdbMovies->cacheKey()))->toBeNull();
    });

    it('does not advance the movies marker when a changes-phase re-hydrate fails', function (): void {
        // Arrange
        Cache::flush();
        Exceptions::fake();
        // Empty export → a clean insert phase; a locally-held changed id (345, present
        // in the changes feed) whose detail 500s persistently makes the CHANGES phase
        // report failure on its own — a distinct failure site from the insert phase.
        Movie::factory()->create(['_tmdb_id' => 345, 'tmdb_synced_at' => now()]);
        Http::fake([
            '*movie_ids*' => Http::response(gzencode('')),
            '*/movie/changes*' => function (Request $request) {
                parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

                return (int) ($query['page'] ?? 1) === 2
                    ? Http::response(fixtureBytes('Catalog/tmdb/movie_changes_page2.json'))
                    : Http::response(fixtureBytes('Catalog/tmdb/movie_changes_page1.json'));
            },
            '*api.themoviedb.org*' => fn (Request $request) => Str::endsWith((string) parse_url($request->url(), PHP_URL_PATH), '/movie/345')
                ? Http::response('', 500)
                : Http::response('', 404),
        ]);

        // Act
        $this->artisan('catalog:sync-movies');

        // Assert
        expect(Cache::get(SyncFeed::TmdbMovies->cacheKey()))->toBeNull();
    });
});

describe('catalog:sync-movies end-of-leg reindex', function (): void {
    it('reindexes every movie the leg touched, exactly once', function (): void {
        // Arrange
        // The spy is registered LAST: the Searchable trait syncs on every model save, so
        // a spy installed earlier would also capture Arrange's own writes and no row
        // could ever look un-reindexed.
        fakeTmdbSync();
        $capturedChunks = spyOnScoutEngine();

        // Act
        $this->artisan('catalog:sync-movies');

        // Assert
        $matrix = Movie::where('_tmdb_id', 603)->firstOrFail();
        expect(reindexedIds($capturedChunks()))->toBe([$matrix->id]);
    });

    it('does not reindex a movie the leg never touched', function (): void {
        // Arrange
        // _tmdb_id 9000001 appears in neither the export nor the changes feed, so the
        // leg never writes this row. Its updated_at is stamped stale EXPLICITLY: the
        // watermark comparison is `>=` over second-precision timestamps, so a row saved
        // inside the leg's own start second would otherwise sweep in as "touched".
        $untouched = Movie::factory()->create(['_tmdb_id' => 9_000_001, 'tmdb_synced_at' => now()]);
        Movie::query()->whereKey($untouched->id)->update(['updated_at' => now()->subDay()]);
        fakeTmdbSync();
        $capturedChunks = spyOnScoutEngine();

        // Act
        $this->artisan('catalog:sync-movies');

        // Assert
        // Both halves in one test: asserting the absence alone would pass on a run that
        // indexed nothing at all.
        $matrix = Movie::where('_tmdb_id', 603)->firstOrFail();
        expect(reindexedIds($capturedChunks()))->toContain($matrix->id);
        expect(reindexedIds($capturedChunks()))->not->toContain($untouched->id);
    });

    it('still reindexes rows touched before a changes-feed failure', function (): void {
        // Arrange
        Cache::flush();
        Exceptions::fake();
        // The real export still inserts 603, so the leg genuinely touches a row, while
        // every /movie/changes page 404s — TMDB raises that as a fatal TmdbRequestFailed
        // the update phase reports rather than propagates, holding the marker back.
        Http::fake([
            '*movie_ids*' => Http::response(fixtureBytes('Catalog/tmdb/movie_ids.json.gz')),
            '*/movie/changes*' => Http::response('', 404),
            '*api.themoviedb.org*' => fn (Request $request) => Str::contains($request->url(), '/movie/603')
                ? Http::response(fixtureBytes('Catalog/tmdb/movie.json'))
                : Http::response('', 404),
        ]);
        $capturedChunks = spyOnScoutEngine();

        // Act
        $this->artisan('catalog:sync-movies')->assertExitCode(0);

        // Assert
        $matrix = Movie::where('_tmdb_id', 603)->firstOrFail();
        expect(Cache::get(SyncFeed::TmdbMovies->cacheKey()))->toBeNull();
        expect(reindexedIds($capturedChunks()))->toContain($matrix->id);
    });
});
