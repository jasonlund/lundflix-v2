<?php

declare(strict_types=1);

use App\Domains\Catalog\Enums\SyncFeed;
use App\Domains\Catalog\Exceptions\TmdbRequestFailed;
use App\Domains\Catalog\Models\Movie;
use App\Domains\Catalog\Support\SyncMarker;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Symfony\Component\Console\Exception\InvalidOptionException;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Fixtures (byte-exact real TMDB slices)
|--------------------------------------------------------------------------
| tests/Fixtures/Catalog/tmdb/movie.json — the /movie/603 detail response
|   (The Matrix, imdb_id tt0133093) with appended images.{posters,backdrops,logos}.
| tests/Fixtures/Catalog/tmdb/movie_changes_page{1,2}.json — hand-authored
|   representative pages approximating the /movie/changes wire format, not verbatim
|   live captures; page 1 declares total_pages:2 so the client pages through.
|
| This leg reads the changes feed and nothing else, so the ids export is stubbed
| in every fake with an EMPTY body: the stub exists only so a leg that still
| downloads the export fails on its behavioural assertion rather than dying as a
| stray request (stray requests are globally prevented). Nothing can reach the
| catalog through it.
|
| Hand-authored (synthetic) bodies, for inputs no real capture can supply:
| — the arbitrary-ids `/movie/changes` page built by fakeTmdbChangedIds() —
|   `{"id":N}` results sized to straddle the probe buffer and the hydrate batch
|   (whose sizes differ), which no committed capture provides — plus a minimal
|   `{"id":N,"title":"Movie N"}` detail body per requested id, images omitted so
|   the volume runs stay fast.
| — the empty `/movie/changes` results page, for the runs that must legitimately
|   ingest nothing.
*/

/**
 * The happy-path fake: the changes feed reports exactly id 603 and the detail
 * host serves The Matrix for it, 404ing every other id.
 *
 * The changes feed lives on the TMDB API host too, so its stub is listed BEFORE
 * the generic detail stub.
 */
function fakeTmdbSync(): void
{
    Http::fake([
        '*movie_ids*' => Http::response(gzencode('')),
        '*/movie/changes*' => Http::response('{"results":[{"id":603}],"page":1,"total_pages":1,"total_results":1}'),
        '*api.themoviedb.org*' => fn (Request $request) => Str::contains($request->url(), '/movie/603')
            ? Http::response(fixtureBytes('Catalog/tmdb/movie.json'))
            : Http::response('', 404),
    ]);
}

/*
| The multi-page variant: movie_changes_page1.json declares total_pages:2, so the
| client pages through to page 2 (movie_changes_page2.json). The Matrix detail body
| is re-keyed onto id 345 (the only synthetic touch, an accepted pattern here) so
| the detail-upsert — which keys on the payload's id — lands on the existing
| _tmdb_id 345 row; every other detail id 404s.
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
 * Fakes a single-page changes feed carrying exactly the given ids, each resolving
 * to a minimal detail body. $onDetail observes every detail request, for the
 * interleaving timeline.
 *
 * @param  list<int>  $ids
 */
function fakeTmdbChangedIds(array $ids, ?Closure $onDetail = null): void
{
    Http::fake([
        '*movie_ids*' => Http::response(gzencode('')),
        '*/movie/changes*' => Http::response(json_encode([
            'results' => array_map(static fn (int $id): array => ['id' => $id], $ids),
            'page' => 1,
            'total_pages' => 1,
            'total_results' => count($ids),
        ])),
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
| introduces and a page-ONE-404 fake can never produce. Page 1's id 345 hydrates
| off the re-keyed Matrix body, so a row is genuinely written before the throw;
| every other detail 404s.
*/
function fakeTmdbMidStreamChangesFailure(): void
{
    $decoded = json_decode(fixtureBytes('Catalog/tmdb/movie.json'), true);
    $decoded['id'] = 345;
    $detailBody = json_encode($decoded);

    Http::fake([
        '*movie_ids*' => Http::response(gzencode('')),
        '*/movie/changes*' => function (Request $request) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return (int) ($query['page'] ?? 1) === 2
                ? Http::response('', 404)
                : Http::response(fixtureBytes('Catalog/tmdb/movie_changes_page1.json'));
        },
        '*api.themoviedb.org*' => fn (Request $request) => Str::endsWith((string) parse_url($request->url(), PHP_URL_PATH), '/movie/345')
            ? Http::response($detailBody)
            : Http::response('', 404),
    ]);
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

describe('catalog:sync-movies feed-driven ingest', function (): void {
    it('requests no ids export on a default run', function (): void {
        // Arrange
        fakeTmdbSync();

        // Act
        $this->artisan('catalog:sync-movies');

        // Assert
        // Both halves in one test: the absence alone would pass on a run that made no
        // request at all, so the changes-feed read is asserted alongside it.
        Http::assertNotSent(fn (Request $request): bool => Str::contains($request->url(), 'movie_ids'));
        Http::assertSent(fn (Request $request): bool => Str::contains($request->url(), '/movie/changes'));
    });

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
        $matrix = Movie::where('_tmdb_id', 603)->first();
        expect($matrix)->not->toBeNull();
        expect($matrix->media()->where('is_active', true)->count())->toBeGreaterThan(0);
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

    it('rejects the removed --limit option', function (): void {
        // Arrange
        // no state to arrange — option binding fails before the command boots

        // Act & Assert
        // `run()` must be called INSIDE the closure: PendingCommand otherwise defers
        // execution to its destructor and the throw escapes expect() entirely.
        expect(fn () => $this->artisan('catalog:sync-movies', ['--limit' => 1])->run())
            ->toThrow(InvalidOptionException::class);
    });

    it('rejects the removed --fresh option', function (): void {
        // Arrange
        // Option binding must fail before the command boots, so these fakes are never
        // reached; they exist only so a build that still ACCEPTS the option fails on
        // the missing throw rather than dying as a stray request.
        fakeTmdbSync();

        // Act & Assert
        // The window comes from the marker, so there is nothing left for the leg to be
        // fresh about. `run()` inside the closure, for the same PendingCommand reason.
        expect(fn () => $this->artisan('catalog:sync-movies', ['--fresh' => true])->run())
            ->toThrow(InvalidOptionException::class);
    });
});

describe('catalog:sync-movies collapsed insert and refresh', function (): void {
    it('inserts a changed id the catalog does not hold', function (): void {
        // Arrange
        fakeTmdbChangedIds([9100]);

        // Act
        $this->artisan('catalog:sync-movies');

        // Assert
        $inserted = Movie::where('_tmdb_id', 9100)->first();
        expect($inserted)->not->toBeNull();
        expect($inserted->_tmdb_title)->toBe('Movie 9100');
    });

    it('refreshes a changed id the catalog already holds', function (): void {
        // A row the catalog holds but has never hydrated from TMDB — the export was
        // the only thing that could reach it, so once the export is gone the feed has
        // to, or it stays stale forever.
        // Arrange
        Movie::factory()->create(['_tmdb_id' => 9101, 'tmdb_synced_at' => null, '_tmdb_title' => 'Stale']);
        fakeTmdbChangedIds([9101]);

        // Act
        $this->artisan('catalog:sync-movies');

        // Assert
        expect(Movie::where('_tmdb_id', 9101)->first()->_tmdb_title)->toBe('Movie 9101');
    });

    it('tells inserted titles from refreshed ones in the heartbeat', function (): void {
        // One run over both sides of the collapse: 9102 is held and previously synced
        // (a refresh), 9103 is unheld (an insert). Both counts are below the
        // every-1000th beat, so the run's closing totals are the lines that carry them.
        // Arrange
        Movie::factory()->create(['_tmdb_id' => 9102, 'tmdb_synced_at' => now(), '_tmdb_title' => 'Stale']);
        fakeTmdbChangedIds([9102, 9103]);

        // Act
        // Read back as one string rather than chaining expectsOutputToContain(): the
        // mocked writer hands each write to the first matching substring expectation,
        // and `[new tmdb movies 1]` would shadow against a bare tag expectation.
        Artisan::call('catalog:sync-movies');

        // Assert
        expect(Artisan::output())
            ->toContain('  [new tmdb movies 1]')
            ->toContain('  [tmdb movies 1]');
    });
});

describe('catalog:sync-movies heartbeat and batch failures', function (): void {
    it('prints a source-prefixed heartbeat every 1000th hydrated title', function (): void {
        // Arrange
        // 1000 changed ids the catalog already holds, so the refresh counter reaches
        // the every-1000th boundary under the plain tag. Bulk inserts, not factories:
        // 1000 factory saves each round-trip the Searchable trait.
        $ids = range(1, 1000);
        $syncedAt = now()->toDateTimeString();
        foreach (array_chunk($ids, 500) as $chunk) {
            Movie::insert(array_map(
                static fn (int $id): array => ['_tmdb_id' => $id, 'tmdb_synced_at' => $syncedAt],
                $chunk,
            ));
        }
        fakeTmdbChangedIds($ids);

        // The guard has to be `[movies ` — bracket AND trailing space — because the
        // prefixed line `[tmdb movies 1000]` itself contains the substring `movies 1000]`,
        // so a naked `movies` guard would reject the very line it exists to allow. Only
        // the opening bracket immediately followed by the bare tag identifies the old,
        // unprefixed shape.
        // Act & Assert
        $this->artisan('catalog:sync-movies')
            ->expectsOutputToContain('[tmdb movies 1000]')
            ->doesntExpectOutputToContain('[movies ');
    });

    it('continues to the next batch when one batch throws', function (): void {
        // Arrange
        Exceptions::fake();
        // Synthetic changes page: a >1000-id feed page is a structural input a committed
        // real fixture can't practically provide — ids 1..1001 span five 250-id hydrate
        // batches, so the id that throws (1, in batch 1) and the id that must still land
        // (1001, alone in its own batch) sit in different batches.
        $decoded = json_decode(fixtureBytes('Catalog/tmdb/movie.json'), true);
        $decoded['id'] = 1001;
        $lastBatchBody = json_encode($decoded);
        Http::fake([
            '*movie_ids*' => Http::response(gzencode('')),
            '*/movie/changes*' => Http::response(json_encode([
                'results' => array_map(static fn (int $id): array => ['id' => $id], range(1, 1001)),
                'page' => 1,
                'total_pages' => 1,
                'total_results' => 1001,
            ])),
            '*api.themoviedb.org*' => function (Request $request) use ($lastBatchBody) {
                $path = (string) parse_url($request->url(), PHP_URL_PATH);

                return match (true) {
                    Str::endsWith($path, '/movie/1001') => Http::response($lastBatchBody),
                    // One batch-1 id 500s persistently; TMDB aggregates a persistent
                    // non-404 failure into a thrown TmdbRequestFailed, so batch 1 throws.
                    Str::endsWith($path, '/movie/1') => Http::response('', 500),
                    default => Http::response('', 404),
                };
            },
        ]);

        // Act
        // The command reports a failing batch's TmdbRequestFailed rather than
        // throwing, so it runs to completion and processes the later batches regardless.
        $this->artisan('catalog:sync-movies');

        // Assert
        expect(Movie::where('_tmdb_id', 1001)->exists())->toBeTrue();
        Exceptions::assertReported(TmdbRequestFailed::class);
    });
});

describe('catalog:sync-movies run-closing output', function (): void {
    it('reports its exact final count on a run that never reaches the beat interval', function (): void {
        // Arrange
        // The standard happy-path fake: the feed reports only id 603, which the catalog
        // already holds, so exactly one payload is refreshed — far short of the
        // 1000-title beat interval, which is the whole point. The count is pinned to the
        // observed run (one movie row refreshed), not to the interval arithmetic.
        Movie::factory()->create(['_tmdb_id' => 603, 'tmdb_synced_at' => now(), '_tmdb_title' => 'Stale']);
        fakeTmdbSync();

        // Act & Assert
        $this->artisan('catalog:sync-movies')->expectsOutputToContain('  [tmdb movies 1]');
    });

    it('ends the run with a Done. line', function (): void {
        // Arrange
        fakeTmdbSync();

        // Act & Assert
        $this->artisan('catalog:sync-movies')->expectsOutputToContain('Done.');
    });

    it('reports a zero final count on a run that processed nothing', function (): void {
        // Arrange
        // An empty changes feed — the leg's only source — runs the pass through its
        // success path, so the run legitimately upserts zero titles and must still
        // say so.
        fakeTmdbChangedIds([]);

        // Act & Assert
        $this->artisan('catalog:sync-movies')->expectsOutputToContain('  [tmdb movies 0]');
    });
});

describe('catalog:sync-movies heartbeat and elapsed phase lines', function (): void {
    it('beats every 1000th changes-feed id probed', function (): void {
        // Arrange
        // 1001 changed ids whose details all 404, so nothing is ever upserted and the
        // title beat can never fire — a probe beat is the only thing that can prove the
        // run is alive. 1001 straddles the 1000-id probe buffer, so the trailing partial
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

    it('prints the reindex phase line in queued wording when scout queues its index writes', function (): void {
        // Production runs SCOUT_QUEUE=true, where the phase only DISPATCHES the index
        // writes — its elapsed seconds time the dispatch, not the indexing, so the
        // lines must not claim the movies were indexed.
        // Arrange
        Date::setTestNow('2026-07-16 12:00:00');
        config(['scout.queue' => true]);
        Queue::fake();
        fakeTmdbSync();

        // Act
        // Read back as one string rather than chaining expectsOutputToContain(): the
        // closing line CONTAINS the opening one, and the mocked writer hands each write
        // to the first matching substring expectation, so the pair would shadow.
        Artisan::call('catalog:sync-movies');

        // Assert
        expect(Artisan::output())
            ->toContain('Queueing movies for reindex…')
            ->toContain('  [reindex 1 queued]')
            ->toContain('Queueing movies for reindex… done in 0s');
    });

    it('closes every phase line with its elapsed seconds', function (): void {
        // The clock is frozen so `0s` is deterministic: on the real clock a phase that
        // happened to straddle a second boundary would print `1s` and flake. The one
        // ingest phase left both inserts and refreshes, so it can no longer call itself
        // "Updating".
        // Arrange
        Date::setTestNow('2026-07-16 12:00:00');
        fakeTmdbSync();

        // Act & Assert
        $this->artisan('catalog:sync-movies')
            ->expectsOutputToContain('Syncing changed movies… done in 0s')
            ->expectsOutputToContain('Reindexing movies… done in 0s');
    });
});

describe('catalog:sync-movies changes-feed window and batching', function (): void {
    it('refreshes an existing synced movie present in the changes feed', function (): void {
        // Arrange
        Movie::factory()->create(['_tmdb_id' => 345, 'tmdb_synced_at' => now(), '_tmdb_title' => 'Stale']);
        fakeTmdbUpdateSync();

        // Act
        $this->artisan('catalog:sync-movies');

        // Assert
        expect(Movie::where('_tmdb_id', 345)->first()->_tmdb_title)->toBe('The Matrix');
    });

    it('bounds a hydrate batch to HYDRATE_SIZE', function (): void {
        // Arrange
        // 251 changed ids we already hold, one over the 250-id hydrate batch, so a
        // bounded pass writes 250 + 1 = two upserts. Sizing its hydrate loop at the
        // wider 1000 collapses them into one write holding 251 decoded payloads at
        // once. Distinct `_tmdb_id`s per row: the column is uniquely indexed.
        $ids = range(9000, 9250);
        Movie::factory()
            ->count(count($ids))
            ->sequence(...array_map(fn (int $id): array => ['_tmdb_id' => $id], $ids))
            ->create(['tmdb_synced_at' => now()]);
        fakeTmdbChangedIds($ids);
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
        resolve(SyncMarker::class)->advance(SyncFeed::TmdbMovies, now()->subDays(2)->subHours(8)->toImmutable());
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
});

describe('catalog:sync-movies video-flagged details', function (): void {
    it('does not persist a movie whose detail is flagged video true', function (): void {
        // Arrange
        $promo = json_decode(fixtureBytes('Catalog/tmdb/movie.json'), true);
        $promo['id'] = 700;
        $promo['video'] = true;
        $film = json_decode(fixtureBytes('Catalog/tmdb/movie.json'), true);
        $film['id'] = 701;
        Http::fake([
            '*movie_ids*' => Http::response(gzencode('')),
            '*/movie/changes*' => Http::response('{"results":[{"id":700},{"id":701}],"page":1,"total_pages":1,"total_results":2}'),
            '*api.themoviedb.org*' => function (Request $request) use ($promo, $film) {
                $path = (string) parse_url($request->url(), PHP_URL_PATH);

                return match (true) {
                    Str::endsWith($path, '/movie/700') => Http::response(json_encode($promo)),
                    Str::endsWith($path, '/movie/701') => Http::response(json_encode($film)),
                    default => Http::response('', 404),
                };
            },
        ]);

        // Act
        $this->artisan('catalog:sync-movies');

        // Assert
        // Both halves in one test: the absence alone would pass on a run that ingested
        // nothing at all, so its non-promo sibling is asserted alongside it.
        expect(Movie::where('_tmdb_id', 700)->exists())->toBeFalse();
        expect(Movie::where('_tmdb_id', 701)->exists())->toBeTrue();
    });

    it('persists a movie whose detail is not flagged video', function (): void {
        // Arrange
        $detail = json_decode(fixtureBytes('Catalog/tmdb/movie.json'), true);
        $detail['id'] = 702;
        Http::fake([
            '*movie_ids*' => Http::response(gzencode('')),
            '*/movie/changes*' => Http::response('{"results":[{"id":702}],"page":1,"total_pages":1,"total_results":1}'),
            '*api.themoviedb.org*' => fn (Request $request) => Str::endsWith((string) parse_url($request->url(), PHP_URL_PATH), '/movie/702')
                ? Http::response(json_encode($detail))
                : Http::response('', 404),
        ]);

        // Act
        $this->artisan('catalog:sync-movies');

        // Assert
        $film = Movie::where('_tmdb_id', 702)->first();
        expect($film)->not->toBeNull();
        expect($film->_tmdb_title)->toBe('The Matrix');
    });
});

describe('catalog:sync-movies changes-feed failure reporting', function (): void {
    it('reports a persistent changes-feed failure and exits FAILURE', function (): void {
        // Arrange
        Exceptions::fake();
        // The changes feed 404s on every page, which TMDB raises as a fatal
        // TmdbRequestFailed the leg must report rather than propagate.
        Http::fake([
            '*movie_ids*' => Http::response(gzencode('')),
            '*/movie/changes*' => Http::response('', 404),
            '*api.themoviedb.org*' => Http::response('', 404),
        ]);

        // Act
        $this->artisan('catalog:sync-movies')->assertExitCode(Command::FAILURE);

        // Assert
        Exceptions::assertReported(TmdbRequestFailed::class);
    });

    it('reports a mid-stream changes-feed failure and exits FAILURE', function (): void {
        // Arrange
        Cache::flush();
        Exceptions::fake();
        fakeTmdbMidStreamChangesFailure();

        // Act
        $this->artisan('catalog:sync-movies')->assertExitCode(Command::FAILURE);

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
        expect(Cache::get(SyncFeed::TmdbMovies->cacheKey()))->toBe(now()->toIso8601String());
    });

    it('does not advance the movies marker when an inserted title fails to hydrate', function (): void {
        // Arrange
        Cache::flush();
        Exceptions::fake();
        // The lone changed id is one we do not hold, so it is an insert; its detail 500s
        // persistently, and the pool aggregates that as a per-id failure and drops the
        // key from its result, so the pass reports failure.
        Http::fake([
            '*movie_ids*' => Http::response(gzencode('')),
            '*/movie/changes*' => Http::response('{"results":[{"id":500}],"page":1,"total_pages":1,"total_results":1}'),
            '*api.themoviedb.org*' => fn (Request $request) => Str::endsWith((string) parse_url($request->url(), PHP_URL_PATH), '/movie/500')
                ? Http::response('', 500)
                : Http::response('', 404),
        ]);

        // Act
        $this->artisan('catalog:sync-movies');

        // Assert
        expect(Cache::get(SyncFeed::TmdbMovies->cacheKey()))->toBeNull();
    });

    it('does not advance the movies marker when a refreshed title fails to hydrate', function (): void {
        // Arrange
        Cache::flush();
        Exceptions::fake();
        // A locally-held changed id (345, present in the changes feed) whose detail 500s
        // persistently — a failure on the refresh side of the pass, distinct from the
        // insert side above.
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
        $matrix = Movie::where('_tmdb_id', 603)->first();
        expect($matrix)->not->toBeNull();
        expect(reindexedIds($capturedChunks()))->toBe([$matrix?->id]);
    });

    it('does not reindex a movie the leg never touched', function (): void {
        // Arrange
        // _tmdb_id 9000001 never appears in the changes feed, so the leg never writes
        // this row. Its updated_at is stamped stale EXPLICITLY: the watermark comparison
        // is `>=` over second-precision timestamps, so a row saved inside the leg's own
        // start second would otherwise sweep in as "touched".
        $untouched = Movie::factory()->create(['_tmdb_id' => 9_000_001, 'tmdb_synced_at' => now()]);
        Movie::query()->whereKey($untouched->id)->update(['updated_at' => now()->subDay()]);
        fakeTmdbSync();
        $capturedChunks = spyOnScoutEngine();

        // Act
        $this->artisan('catalog:sync-movies');

        // Assert
        // Both halves in one test: asserting the absence alone would pass on a run that
        // indexed nothing at all.
        $matrix = Movie::where('_tmdb_id', 603)->first();
        expect($matrix)->not->toBeNull();
        expect(reindexedIds($capturedChunks()))->toContain($matrix?->id);
        expect(reindexedIds($capturedChunks()))->not->toContain($untouched->id);
    });

    it('still reindexes rows touched before a changes-feed failure', function (): void {
        // Arrange
        Cache::flush();
        Exceptions::fake();
        // Page 1 of the feed lands and refreshes the held title 345, so the leg
        // genuinely touches a row, before page TWO 404s — TMDB raises that as a fatal
        // TmdbRequestFailed the leg reports rather than propagates, holding the marker
        // back while the row it already wrote stays on disk.
        $held = Movie::factory()->create(['_tmdb_id' => 345, 'tmdb_synced_at' => now(), '_tmdb_title' => 'Stale']);
        fakeTmdbMidStreamChangesFailure();
        $capturedChunks = spyOnScoutEngine();

        // Act
        $this->artisan('catalog:sync-movies')->assertExitCode(Command::FAILURE);

        // Assert
        expect(Cache::get(SyncFeed::TmdbMovies->cacheKey()))->toBeNull();
        expect(reindexedIds($capturedChunks()))->toContain($held->id);
    });
});

describe('catalog:sync-movies capped changes window', function (): void {
    it('reports the span the 14-day cap left uncovered', function (): void {
        // Arrange
        // A marker 30 days stale: SyncMarker floors `since` at now − 14d, so the
        // span between the marker and that floor is never fetched and never
        // retried. The leg has to say so — silently covering only the last 14
        // days is how a stalled marker stayed invisible on production for months.
        Cache::flush();
        Date::setTestNow('2026-07-16 12:00:00');
        resolve(SyncMarker::class)->advance(SyncFeed::TmdbMovies, now()->subDays(30)->toImmutable());
        fakeTmdbChangedIds([]);

        // Act
        // Read back as one string rather than chaining expectsOutputToContain():
        // both halves live on the SAME line, and the mocked writer hands each write
        // to the first matching substring expectation, so the pair would shadow.
        Artisan::call('catalog:sync-movies');

        // Assert
        // The span is named, not just the fact of a gap: 2026-06-16 is the marker
        // less its 6h overlap, 2026-07-02 the floor — the fortnight in between is
        // what no run will ever fetch.
        expect(Artisan::output())
            ->toContain('1 changes-feed window failed;')
            ->toContain('2026-06-16 to 2026-07-02 uncovered')
            ->toContain('marker not advanced');
    });

    it('exits FAILURE on a capped window', function (): void {
        // Arrange
        Cache::flush();
        Date::setTestNow('2026-07-16 12:00:00');
        resolve(SyncMarker::class)->advance(SyncFeed::TmdbMovies, now()->subDays(30)->toImmutable());
        fakeTmdbChangedIds([]);

        // Act & Assert
        $this->artisan('catalog:sync-movies')->assertExitCode(Command::FAILURE);
    });

    it('leaves the marker unadvanced so the alarm persists until an operator seeds', function (): void {
        // Arrange
        Cache::flush();
        Date::setTestNow('2026-07-16 12:00:00');
        $stale = now()->subDays(30)->toImmutable();
        resolve(SyncMarker::class)->advance(SyncFeed::TmdbMovies, $stale);
        fakeTmdbChangedIds([]);

        // Act
        $this->artisan('catalog:sync-movies');

        // Assert
        // Unchanged, not merely un-advanced-to-now: a capped run that quietly moved
        // the marker forward would erase the evidence of its own gap.
        expect(Cache::get(SyncFeed::TmdbMovies->cacheKey()))->toBe($stale->toIso8601String());
    });

    it('still covers the 14 days it can reach on a capped run', function (): void {
        // Arrange
        // The guard reports the gap; it does not skip the work. The floored window
        // is still requested, and a title inside it still lands.
        Cache::flush();
        Date::setTestNow('2026-07-16 12:00:00');
        resolve(SyncMarker::class)->advance(SyncFeed::TmdbMovies, now()->subDays(30)->toImmutable());
        fakeTmdbChangedIds([9500]);

        // Act
        $this->artisan('catalog:sync-movies');

        // Assert
        assertRequestedChangesWindow('2026-07-02', '2026-07-16');
        expect(Movie::where('_tmdb_id', 9500)->exists())->toBeTrue();
    });

    it('reports no uncovered span when the marker sits inside the cap', function (): void {
        // Arrange
        Cache::flush();
        Date::setTestNow('2026-07-16 12:00:00');
        resolve(SyncMarker::class)->advance(SyncFeed::TmdbMovies, now()->subDays(2)->toImmutable());
        fakeTmdbChangedIds([]);

        // Act & Assert
        // Both halves in one test: the absence alone would pass on a run that
        // failed for some unrelated reason and never reached the closing summary.
        $this->artisan('catalog:sync-movies')
            ->doesntExpectOutputToContain('changes-feed window failed')
            ->assertExitCode(Command::SUCCESS);
    });
});
