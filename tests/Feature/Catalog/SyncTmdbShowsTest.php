<?php

declare(strict_types=1);

use App\Domains\Catalog\Enums\SyncFeed;
use App\Domains\Catalog\Exceptions\TmdbRequestFailed;
use App\Domains\Catalog\Exceptions\TmdbShowCrosswalkCollision;
use App\Domains\Catalog\Models\Show;
use App\Domains\Catalog\Support\SyncMarker;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Fixtures (byte-exact real TMDB slices)
|--------------------------------------------------------------------------
| Phase 1 hydrates OUR OWN not-yet-synced shows, matched by id — it never
| walks the daily export. A row already carrying _tmdb_id hydrates directly via
| /tv/{id}; an imdb-only row (_tmdb_id null) reconciles through
| /find/{imdbId}?external_source=imdb_id, stamping tv_results[0].id before it
| hydrates. Empty tv_results → the row stays TVDB-only (no hydrate, no error).
|
| find_tv_by_imdb.json — real /find/tt0903747 capture; tv_results[0].id 1396
|   (Breaking Bad), movie_results empty.
| find_by_imdb.json — real /find/tt0133093 capture (The Matrix); tv_results
|   EMPTY → the imdb-only row stays TVDB-only.
| tv.json — the /tv/1399 detail response (Game of Thrones, _tmdb_name
|   "Game of Thrones") with an images block. Its body is re-keyed onto id 1396
|   to serve the reconcile hydrate (the only synthetic touch, accepted here).
|
| Hand-authored (synthetic) bodies, for inputs no real capture can supply:
| — the empty single-page /tv/changes results page, which drives the update phase
|   through its success path so it issues no anti-join query of its own.
| — the single-page /tv/changes body built by fakeTmdbShowVolumeSync() from an
|   arbitrary id list — `{"id":N}` results sized to straddle the hydrate batch and
|   the probe buffer (whose sizes differ), which no committed capture provides.
| — the images-stripped, per-id re-keyed tv.json detail body those volume runs
|   serve: real bytes minus the 643-entry images block, which no capture ships
|   without.
|
| The TMDB API host is faked and stray requests are globally prevented.
*/

function fakeTmdbShowSync(): void
{
    Http::fake([
        '*/find/tt0903747*' => Http::response(fixtureBytes('Catalog/tmdb/find_tv_by_imdb.json')),
        '*/find/tt0133093*' => Http::response(fixtureBytes('Catalog/tmdb/find_by_imdb.json')),
        '*/tv/changes*' => Http::response('{"results":[],"page":1,"total_pages":1,"total_results":0}'),
        '*api.themoviedb.org*' => function (Request $request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            if (Str::endsWith($path, '/tv/1399')) {
                return Http::response(fixtureBytes('Catalog/tmdb/tv.json'));
            }
            if (Str::endsWith($path, '/tv/1396')) {
                // Re-key the Game of Thrones body onto id 1396 so the reconcile
                // hydrate (which keys on the payload's id) lands on the row.
                $body = json_decode(fixtureBytes('Catalog/tmdb/tv.json'), true);
                $body['id'] = 1396;

                return Http::response(json_encode($body));
            }

            return Http::response('', 404);
        },
    ]);
}

/*
| Fakes the three hosts the update-changed phase touches. The export is empty so
| the insert-new phase is a no-op and can't interfere with the update phase.
| tv_changes_page1.json declares total_pages:2, so the client pages through to
| page 2 (tv_changes_page2.json) — both are hand-authored representative fixtures
| approximating the /tv/changes wire format, not verbatim live captures. The
| changes feed lives on the TMDB API host too, so its stub is listed BEFORE the
| generic detail stub. The Game of Thrones detail body is re-keyed onto id 23310
| (the only synthetic touch, an accepted pattern here) so the detail-upsert —
| which keys on the payload's id — lands on the existing _tmdb_id 23310 row;
| every other detail id 404s.
*/
function fakeTmdbShowUpdateSync(): void
{
    $decoded = json_decode(fixtureBytes('Catalog/tmdb/tv.json'), true);
    $decoded['id'] = 23310;
    $detailBody = json_encode($decoded);

    Http::fake([
        '*/tv/changes*' => function (Request $request) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return (int) ($query['page'] ?? 1) === 2
                ? Http::response(fixtureBytes('Catalog/tmdb/tv_changes_page2.json'))
                : Http::response(fixtureBytes('Catalog/tmdb/tv_changes_page1.json'));
        },
        '*api.themoviedb.org*' => fn (Request $request) => Str::endsWith((string) parse_url($request->url(), PHP_URL_PATH), '/tv/23310')
            ? Http::response($detailBody)
            : Http::response('', 404),
    ]);
}

/*
| The mid-stream variant of fakeTmdbShowUpdateSync(): page 1 of /tv/changes is
| served verbatim (tv_changes_page1.json, which declares total_pages:2), then page
| TWO 404s — so the fatal TmdbRequestFailed lands only AFTER the feed has already
| yielded page 1's ids, the failure ordering a lazily-paged feed introduces and the
| page-ONE-404 fake can never produce. Every detail 404s: this fake exists to
| observe what the update phase does with a half-read feed, not to hydrate.
*/
function fakeTmdbShowMidStreamChangesFailure(): void
{
    Http::fake([
        '*/tv/changes*' => function (Request $request) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return (int) ($query['page'] ?? 1) === 2
                ? Http::response('', 404)
                : Http::response(fixtureBytes('Catalog/tmdb/tv_changes_page1.json'));
        },
        '*api.themoviedb.org*' => Http::response('', 404),
    ]);
}

/*
| Shared by the marker-derived window tests: asserts the /tv/changes request
| carried the given start/end dates, ignoring every non-changes request.
*/
function assertRequestedShowChangesWindow(string $start, string $end): void
{
    Http::assertSent(function (Request $request) use ($start, $end): bool {
        if (! Str::contains($request->url(), '/tv/changes')) {
            return false;
        }
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return ($query['start_date'] ?? null) === $start
            && ($query['end_date'] ?? null) === $end;
    });
}

/**
 * Fakes the two endpoints a volume run touches: a single-page /tv/changes body
 * listing exactly the given ids (empty by default, so the changes phase is a
 * no-op), and a detail body for every requested id.
 *
 * The detail body is the real /tv/1399 capture with its images block dropped and
 * its id re-keyed per request — the upsert keys on the payload's id, so re-keying
 * lands each response on its own row. The images block MUST go: tv.json carries
 * 643 image entries, so serving it across hundreds of rows fires ~643k media
 * upserts and exhausts memory. These runs measure batch sizing, not artwork.
 *
 * @param  list<int>  $changedIds
 */
function fakeTmdbShowVolumeSync(array $changedIds = []): void
{
    $body = json_decode(fixtureBytes('Catalog/tmdb/tv.json'), true);
    unset($body['images']);

    Http::fake([
        '*/tv/changes*' => Http::response(json_encode([
            'results' => array_map(fn (int $id): array => ['id' => $id], $changedIds),
            'page' => 1,
            'total_pages' => 1,
            'total_results' => count($changedIds),
        ])),
        '*api.themoviedb.org*' => function (Request $request) use ($body) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);

            if (preg_match('#/tv/(\d+)$#', $path, $matches) === 1) {
                $body['id'] = (int) $matches[1];

                return Http::response(json_encode($body));
            }

            return Http::response('', 404);
        },
    ]);
}

/*
| Whether a statement is the changes phase's anti-join against our synced rows.
| `tmdb_synced_at` alone can't say so here: unlike movies, the shows INSERT phase
| filters on that same column (`whereNull('tmdb_synced_at')`), so the candidate
| stream's own query names it too. The buffered `in (…)` list is what distinguishes
| the anti-join — the candidate stream is PK-paginated, never id-listed.
*/
function isShowChangesProbe(string $sql): bool
{
    return isSyncedProbe($sql, requiresIdList: true);
}

/**
 * The changes-phase anti-join statements captured in the query log.
 *
 * @return Collection<int, array{query: string, bindings: array<int, mixed>}>
 */
function loggedShowChangesProbes(): Collection
{
    return loggedStatements(isShowChangesProbe(...));
}

/**
 * The narrow selects against `shows`, further confined to an `in (…)` list with no
 * `_imdb_id`. Every clause earns its place: the candidate stream ALREADY selects a
 * narrow `id, _tmdb_id, _imdb_id`, so `_tmdb_id` + "no `*`" is satisfied before any
 * change — `in (…)` and the absent `_imdb_id` are what single out the images
 * resolve. The changes anti-join is excluded — it plucks one column off an id list
 * too and would otherwise satisfy the shape on its own.
 *
 * @return Collection<int, array{query: string, bindings: array<int, mixed>}>
 */
function narrowShowSelects(): Collection
{
    return narrowSelects('shows', fn (string $sql): bool => Str::contains($sql, 'in (')
        && ! Str::contains($sql, '_imdb_id')
        && ! isShowChangesProbe($sql));
}

describe('catalog:sync-shows-tmdb insert-phase hydration', function (): void {
    it('hydrates a not-yet-synced show carrying a _tmdb_id directly', function (): void {
        // Arrange
        Show::factory()->withTvdb()->create(['_tmdb_id' => 1399, 'tmdb_synced_at' => null]);
        fakeTmdbShowSync();

        // Act
        $this->artisan('catalog:sync-shows-tmdb');

        // Assert
        expect(Show::where('_tmdb_id', 1399)->firstOrFail()->_tmdb_name)->toBe('Game of Thrones');
        expect(Show::count())->toBe(1);
    });

    it('reconciles an imdb-only show by resolving its tmdb id via /find', function (): void {
        // Arrange
        Show::factory()->withTvdb()->create(['_imdb_id' => 'tt0903747', '_tmdb_id' => null, 'tmdb_synced_at' => null]);
        fakeTmdbShowSync();

        // Act
        $this->artisan('catalog:sync-shows-tmdb');

        // Assert
        $got = Show::firstOrFail();
        expect($got->_tmdb_id)->toBe(1396);
        expect($got->_tmdb_name)->not->toBeNull();
    });

    it('leaves an imdb-only show TVDB-only when /find returns no tv results', function (): void {
        // Arrange
        Show::factory()->withTvdb()->create(['_imdb_id' => 'tt0133093', '_tmdb_id' => null, 'tmdb_synced_at' => null]);
        fakeTmdbShowSync();

        // Act
        $this->artisan('catalog:sync-shows-tmdb');

        // Assert
        $got = Show::firstOrFail();
        expect($got->_tmdb_id)->toBeNull();
        expect($got->tmdb_synced_at)->toBeNull();
        Http::assertNotSent(fn (Request $request): bool => (bool) preg_match(
            '#/tv/\d+$#',
            (string) parse_url($request->url(), PHP_URL_PATH),
        ));
    });

    it('persists the hydrated show images into media', function (): void {
        // Arrange
        Show::factory()->withTvdb()->create(['_tmdb_id' => 1399, 'tmdb_synced_at' => null]);
        fakeTmdbShowSync();

        // Act
        $this->artisan('catalog:sync-shows-tmdb');

        // Assert
        $got = Show::where('_tmdb_id', 1399)->firstOrFail();
        expect($got->media()->where('is_active', true)->count())->toBeGreaterThan(0);
    });

    it('selects only id and _tmdb_id when resolving upserted shows for images', function (): void {
        // Arrange
        // The empty changes page matters twice over: it keeps the update phase on its
        // success path, and it stops the changes anti-join — which plucks a single
        // column off an id list, the very shape under test — from ever being issued.
        Show::factory()->withTvdb()->create(['_tmdb_id' => 1399, 'tmdb_synced_at' => null]);
        fakeTmdbShowSync();
        DB::enableQueryLog();

        // Act
        $this->artisan('catalog:sync-shows-tmdb');

        // Assert
        // Presence, not absence: the wide Scout select always shares this log, so the
        // only observable proof is that a narrow one was issued as well.
        expect(narrowShowSelects()->count())->toBeGreaterThanOrEqual(1);
    });

    it('creates no identity-less rows while hydrating existing shows', function (): void {
        // Arrange
        Show::factory()->withTvdb()->create(['_tmdb_id' => 1399, 'tmdb_synced_at' => null]);
        Show::factory()->withTvdb()->create(['_imdb_id' => 'tt0903747', '_tmdb_id' => null, 'tmdb_synced_at' => null]);
        fakeTmdbShowSync();
        $before = Show::count();

        // Act
        $this->artisan('catalog:sync-shows-tmdb');

        // Assert
        expect(Show::count())->toBe($before);
    });

    it('skips an already-synced show on a default run', function (): void {
        // Arrange
        Show::factory()->create(['_tmdb_id' => 1399, 'tmdb_synced_at' => now()]);
        fakeTmdbShowSync();

        // Act
        $this->artisan('catalog:sync-shows-tmdb');

        // Assert
        Http::assertNotSent(fn (Request $request): bool => Str::contains($request->url(), '/tv/1399'));
    });

    it('reprocesses an already-synced show with --fresh', function (): void {
        // Arrange
        Show::factory()->create(['_tmdb_id' => 1399, 'tmdb_synced_at' => now()]);
        fakeTmdbShowSync();

        // Act
        $this->artisan('catalog:sync-shows-tmdb', ['--fresh' => true]);

        // Assert
        Http::assertSent(fn (Request $request): bool => Str::contains($request->url(), '/tv/1399'));
    });
});

describe('catalog:sync-shows-tmdb changes-feed update phase', function (): void {
    it('refreshes an existing synced show present in the changes feed', function (): void {
        // Arrange
        Show::factory()->create(['_tmdb_id' => 23310, 'tmdb_synced_at' => now(), '_tmdb_name' => 'Stale']);
        fakeTmdbShowUpdateSync();

        // Act
        $this->artisan('catalog:sync-shows-tmdb');

        // Assert
        expect(Show::where('_tmdb_id', 23310)->first()->_tmdb_name)->toBe('Game of Thrones');
    });

    it('ignores a changed tv id not in the local catalog', function (): void {
        // Arrange
        Show::factory()->create(['_tmdb_id' => 23310, 'tmdb_synced_at' => now()]);
        fakeTmdbShowUpdateSync();

        // Act
        $this->artisan('catalog:sync-shows-tmdb');

        // Assert
        Http::assertNotSent(fn (Request $request): bool => Str::contains($request->url(), '/tv/325296'));
    });

    it('bounds a changes-phase hydrate batch to HYDRATE_SIZE', function (): void {
        // Arrange
        // 251 changed ids we already hold, one over the 250-id hydrate batch, so a
        // bounded changes phase writes 250 + 1 = two upserts. Sizing its hydrate loop
        // at the wider probe buffer collapses them into one write holding 251 decoded
        // payloads at once. Every row is already synced, so the insert phase is a no-op
        // and the count can only come from the changes phase.
        $ids = range(700_000, 700_250);
        $syncedAt = now()->toDateTimeString();
        Show::insert(array_map(
            fn (int $id): array => ['_tmdb_id' => $id, 'tmdb_synced_at' => $syncedAt],
            $ids,
        ));
        fakeTmdbShowVolumeSync($ids);
        // Enabled LAST, after the seeding: Show::insert() is itself an `insert into
        // shows` and would be counted alongside the upserts under test.
        DB::enableQueryLog();

        // Act
        $this->artisan('catalog:sync-shows-tmdb');

        // Assert
        expect(loggedInsertsInto('shows')->count())->toBe(2);
    });

    it('buffers the changes anti-join at PROBE_SIZE', function (): void {
        // Arrange
        // 1001 changed ids we already hold, one over the 1000-id probe buffer, so the
        // anti-join resolves in 1000 + 1 = two queries rather than one unbounded
        // whereIn that risks the packet/placeholder limit.
        //
        // This one passes BEFORE the batching rewrite as well — it is a deliberate
        // regression guard, not a driver: it is what stops the rewrite from collapsing
        // the probe buffer down onto the narrower hydrate batch, which would quadruple
        // these queries while saving nothing (a buffer holds bare ints, a hydrate batch
        // holds decoded payloads).
        $ids = range(800_000, 801_000);
        $syncedAt = now()->toDateTimeString();
        Show::insert(array_map(
            fn (int $id): array => ['_tmdb_id' => $id, 'tmdb_synced_at' => $syncedAt],
            $ids,
        ));
        fakeTmdbShowVolumeSync($ids);
        // Enabled LAST, after the seeding: Show::insert() names tmdb_synced_at in its
        // column list and would otherwise be mistaken for an anti-join.
        DB::enableQueryLog();

        // Act
        $this->artisan('catalog:sync-shows-tmdb');

        // Assert
        $probes = loggedShowChangesProbes();
        expect($probes->count())->toBe(2);
        expect($probes->map(fn (array $entry): int => count($entry['bindings']))->min())->toBeGreaterThanOrEqual(1);
        expect($probes->map(fn (array $entry): int => count($entry['bindings']))->max())->toBeLessThanOrEqual(1000);
    });

    it('requests the changes window from the cached marker with a 6h overlap', function (): void {
        // Arrange
        Cache::flush();
        Date::setTestNow('2026-07-16 12:00:00');
        // Marker at 2026-07-14 04:00 — under 6h into its day, so the three candidate
        // window starts fall on three different calendar days, the only granularity
        // the assertion compares: marker − 6h → 2026-07-13; overlap dropped →
        // 2026-07-14; marker ignored for the 24h fallback → 2026-07-15.
        resolve(SyncMarker::class)->advance(SyncFeed::TmdbShows, now()->subDays(2)->subHours(8)->toImmutable());
        Show::factory()->create(['_tmdb_id' => 23310, 'tmdb_synced_at' => now()]);
        fakeTmdbShowUpdateSync();

        // Act
        $this->artisan('catalog:sync-shows-tmdb');

        // Assert
        assertRequestedShowChangesWindow('2026-07-13', '2026-07-16');
    });

    it('falls back to a 24h changes window when no marker is cached', function (): void {
        // Arrange
        Cache::flush();
        Date::setTestNow('2026-07-16 12:00:00');
        Show::factory()->create(['_tmdb_id' => 23310, 'tmdb_synced_at' => now()]);
        fakeTmdbShowUpdateSync();

        // Act
        $this->artisan('catalog:sync-shows-tmdb');

        // Assert
        assertRequestedShowChangesWindow('2026-07-15', '2026-07-16');
    });

    it('skips the update phase with --fresh', function (): void {
        // Arrange
        Show::factory()->create(['_tmdb_id' => 23310, 'tmdb_synced_at' => now()]);
        fakeTmdbShowUpdateSync();

        // Act
        $this->artisan('catalog:sync-shows-tmdb', ['--fresh' => true]);

        // Assert
        Http::assertNotSent(fn (Request $request): bool => Str::contains($request->url(), '/tv/changes'));
    });
});

describe('catalog:sync-shows-tmdb changes-feed failure handling', function (): void {
    it('reports a persistent changes-feed failure and exits FAILURE', function (): void {
        // Arrange
        Exceptions::fake();
        // Empty export → the insert phase is a no-op; the changes feed 404s on every
        // page, which TMDB raises as a fatal TmdbRequestFailed the update phase must
        // report rather than propagate.
        Http::fake([
            '*/tv/changes*' => Http::response('', 404),
            '*api.themoviedb.org*' => Http::response('', 404),
        ]);

        // Act
        $this->artisan('catalog:sync-shows-tmdb')->assertExitCode(Command::FAILURE);

        // Assert
        Exceptions::assertReported(TmdbRequestFailed::class);
    });

    it('reports a mid-stream changes-feed failure and exits FAILURE', function (): void {
        // Arrange
        Exceptions::fake();
        // The feed fails on page TWO, not page one: it yields page 1's ids first and
        // only then 404s, so the throw surfaces mid-iteration rather than at the call.
        // The lone row is already synced, so the insert phase is a no-op and both the
        // exit code and the report can only come from the update phase.
        Show::factory()->create(['_tmdb_id' => 23310, 'tmdb_synced_at' => now()]);
        fakeTmdbShowMidStreamChangesFailure();

        // A half-read feed page names no entity that failed, so the run can only
        // count the window it never finished reading.
        // Act & Assert
        $this->artisan('catalog:sync-shows-tmdb')
            ->expectsOutputToContain('1 changes-feed window failed; marker not advanced.')
            ->assertExitCode(Command::FAILURE);

        // Assert
        Exceptions::assertReported(TmdbRequestFailed::class);
    });

    it('closes the run with the failed show count and the marker consequence', function (): void {
        // Arrange
        Exceptions::fake();
        // A held candidate carrying _tmdb_id 500 whose /tv/500 detail 500s persistently;
        // the pool aggregates it as a per-id failure and drops the key from its result,
        // so the run ends owing one show.
        Show::factory()->withTvdb()->create(['_tmdb_id' => 500, 'tmdb_synced_at' => null]);
        Http::fake([
            '*/tv/changes*' => Http::response('{"results":[],"page":1,"total_pages":1,"total_results":0}'),
            '*api.themoviedb.org*' => fn (Request $request) => Str::endsWith((string) parse_url($request->url(), PHP_URL_PATH), '/tv/500')
                ? Http::response('', 500)
                : Http::response('', 404),
        ]);

        // Act & Assert
        $this->artisan('catalog:sync-shows-tmdb')
            ->expectsOutputToContain('1 shows failed; marker not advanced.')
            ->doesntExpectOutputToContain('  1 shows failed');
    });

    it('does not hydrate the un-flushed partial buffer when a later feed page fails', function (): void {
        // Arrange
        Exceptions::fake();
        Show::factory()->create(['_tmdb_id' => 23310, 'tmdb_synced_at' => now()]);
        fakeTmdbShowMidStreamChangesFailure();

        // Act
        $this->artisan('catalog:sync-shows-tmdb');

        // Assert
        // Page 1's two ids sit in a buffer far under PROBE_SIZE when page 2 throws, so
        // they are never flushed — the partial buffer must be DROPPED, not hydrated.
        // 23310 is the one held id among them, so an implementation that flushed the
        // remainder from a `finally` would request /tv/23310 and fail this line. Half a
        // window belongs to the next run (which the untouched marker guarantees), not to
        // a run that already knows it read the feed incompletely.
        Http::assertNotSent(fn (Request $request): bool => Str::contains((string) $request->url(), '/tv/23310'));
    });

    it('does not advance the shows marker on a mid-stream changes-feed failure', function (): void {
        // Arrange
        Cache::flush();
        Exceptions::fake();
        Show::factory()->create(['_tmdb_id' => 23310, 'tmdb_synced_at' => now()]);
        fakeTmdbShowMidStreamChangesFailure();

        // Act
        $this->artisan('catalog:sync-shows-tmdb');

        // Assert
        expect(Cache::get(SyncFeed::TmdbShows->cacheKey()))->toBeNull();
    });
});

describe('catalog:sync-shows-tmdb crosswalk collisions', function (): void {
    it('leaves the imdb-only show TVDB-only when its resolved tmdb id collides', function (): void {
        // Arrange
        fakeTmdbShowSync();
        Show::factory()->withTvdb()->create(['_tmdb_id' => 1396, 'tmdb_synced_at' => null, '_tmdb_name' => null]);
        Show::factory()->withTvdb()->create(['_imdb_id' => 'tt0903747', '_tmdb_id' => null, 'tmdb_synced_at' => null]);

        // Act
        $this->artisan('catalog:sync-shows-tmdb');

        // Assert
        $rowB = Show::where('_imdb_id', 'tt0903747')->firstOrFail();
        expect($rowB->_tmdb_id)->toBeNull();
        expect($rowB->tmdb_synced_at)->toBeNull();
    });

    it('still hydrates the colliding id\'s owning row and inserts nothing', function (): void {
        // Arrange
        fakeTmdbShowSync();
        Show::factory()->withTvdb()->create(['_tmdb_id' => 1396, 'tmdb_synced_at' => null, '_tmdb_name' => null]);
        Show::factory()->withTvdb()->create(['_imdb_id' => 'tt0903747', '_tmdb_id' => null, 'tmdb_synced_at' => null]);

        // Act
        $this->artisan('catalog:sync-shows-tmdb');

        // Assert
        expect(Show::where('_tmdb_id', 1396)->firstOrFail()->_tmdb_name)->toBe('Game of Thrones');
        expect(Show::count())->toBe(2);
    });

    it('reports the crosswalk collision', function (): void {
        // Arrange
        Exceptions::fake();
        fakeTmdbShowSync();
        Show::factory()->withTvdb()->create(['_tmdb_id' => 1396, 'tmdb_synced_at' => null, '_tmdb_name' => null]);
        Show::factory()->withTvdb()->create(['_imdb_id' => 'tt0903747', '_tmdb_id' => null, 'tmdb_synced_at' => null]);

        // Act
        $this->artisan('catalog:sync-shows-tmdb');

        // Assert
        Exceptions::assertReported(TmdbShowCrosswalkCollision::class);
    });
});

describe('catalog:sync-shows-tmdb candidate chunking and batching', function (): void {
    it('hydrates every candidate across a set larger than one chunk without skipping rows', function (): void {
        // Arrange
        // One more candidate than four times HYDRATE_SIZE (250), so the run spans five
        // chunkById pages. Each row carries a distinct _tmdb_id; hydration stamps
        // tmdb_synced_at, the very column the default run filters on — proving PK
        // pagination doesn't skip rows whose filtered column mutates mid-iteration.
        $rows = [];
        for ($i = 0; $i < 1001; $i++) {
            $rows[] = ['_tmdb_id' => 500_000 + $i, '_imdb_id' => 'tt'.str_pad((string) (8_000_000 + $i), 7, '0', STR_PAD_LEFT)];
        }
        Show::insert($rows);
        fakeTmdbShowVolumeSync();

        // Act
        $this->artisan('catalog:sync-shows-tmdb');

        // Assert
        expect(Show::whereNull('tmdb_synced_at')->count())->toBe(0);
        expect(Show::count())->toBe(1001);
    });

    it('hydrates the insert phase in HYDRATE_SIZE batches', function (): void {
        // Arrange
        // 501 hydratable candidates all fit inside ONE probe buffer, so the number of
        // upserts can only be decided by the hydrate batch size: 250 + 250 + 1 = three
        // writes. A hydrate batch as wide as the buffer collapses them into a single
        // upsert holding all 501 decoded payloads at once. Distinct _tmdb_id per row:
        // the column is uniquely indexed.
        $rows = [];
        for ($i = 0; $i < 501; $i++) {
            $rows[] = ['_tmdb_id' => 600_000 + $i];
        }
        Show::insert($rows);
        fakeTmdbShowVolumeSync();
        // Enabled LAST, after the seeding: Show::insert() is itself an `insert into
        // shows` and would be counted alongside the upserts under test.
        DB::enableQueryLog();

        // Act
        $this->artisan('catalog:sync-shows-tmdb');

        // Assert
        expect(loggedInsertsInto('shows')->count())->toBe(3);
        expect(Show::whereNotNull('tmdb_synced_at')->count())->toBe(501);
    });

    it('stamps one of two shows sharing an imdb id and never aborts the chunk', function (): void {
        // Arrange
        // Two imdb-only rows legitimately share one _imdb_id; both resolve to the
        // same UNIQUE tmdb id, so only one can be stamped. A direct-id row rides the
        // same chunk to prove the batch is not aborted wholesale.
        fakeTmdbShowSync();
        Show::factory()->withTvdb()->create(['_tmdb_id' => 1399, 'tmdb_synced_at' => null, '_tmdb_name' => null]);
        Show::factory()->withTvdb()->create(['_imdb_id' => 'tt0903747', '_tmdb_id' => null, 'tmdb_synced_at' => null]);
        Show::factory()->withTvdb()->create(['_imdb_id' => 'tt0903747', '_tmdb_id' => null, 'tmdb_synced_at' => null]);

        // Act
        $this->artisan('catalog:sync-shows-tmdb');

        // Assert
        expect(Show::where('_tmdb_id', 1399)->firstOrFail()->_tmdb_name)->toBe('Game of Thrones');
        $shared = Show::where('_imdb_id', 'tt0903747')->get();
        expect($shared->whereNotNull('_tmdb_id'))->toHaveCount(1);
        expect($shared->whereNull('_tmdb_id'))->toHaveCount(1);
        expect($shared->firstWhere('_tmdb_id', 1396)->_tmdb_name)->toBe('Game of Thrones');
        expect(Show::count())->toBe(3);
    });
});

describe('catalog:sync-shows-tmdb heartbeat and elapsed phase lines', function (): void {
    it('beats every 1000th candidate row walked', function (): void {
        // Arrange
        // 1001 candidates, each carrying a distinct _tmdb_id (the column is uniquely
        // indexed), whose every /tv/{id} detail 404s — so nothing hydrates, nothing is
        // upserted, and the upsert heartbeat can never fire — the run's closing total is
        // therefore 0. A scan-unit beat is then the only thing that can show the walk is
        // alive. A 404 stays present-as-key in the pooled result, so it is a miss, not a
        // fetch failure.
        $rows = [];
        for ($i = 0; $i < 1001; $i++) {
            $rows[] = ['_tmdb_id' => 900_000 + $i];
        }
        Show::insert($rows);
        Http::fake([
            '*/tv/changes*' => Http::response('{"results":[],"page":1,"total_pages":1,"total_results":0}'),
            '*api.themoviedb.org*' => Http::response('', 404),
        ]);

        // Act & Assert
        $this->artisan('catalog:sync-shows-tmdb')
            ->expectsOutputToContain('  [scan 1000]')
            ->expectsOutputToContain('  [tmdb shows 0]');
    });

    it('prints the reindex phase line and the heartbeat', function (): void {
        // Arrange
        // A single touched show, so the Action's cumulative count reads 1.
        Show::factory()->withTvdb()->create(['_tmdb_id' => 1399, 'tmdb_synced_at' => null]);
        fakeTmdbShowSync();

        // Act & Assert
        $this->artisan('catalog:sync-shows-tmdb')
            ->expectsOutputToContain('Reindexing shows…')
            ->expectsOutputToContain('  [reindex 1]');
    });

    it('closes every phase line with its elapsed seconds', function (): void {
        // The clock is frozen so `0s` is deterministic: on the real clock a phase that
        // happened to straddle a second boundary would print `1s` and flake. The lone
        // unsynced candidate makes the hydrate phase do real work rather than walk an
        // empty table.
        // Arrange
        Date::setTestNow('2026-07-16 12:00:00');
        Show::factory()->withTvdb()->create(['_tmdb_id' => 1399, 'tmdb_synced_at' => null]);
        fakeTmdbShowSync();

        // Act & Assert
        $this->artisan('catalog:sync-shows-tmdb')
            ->expectsOutputToContain('Hydrating TMDB shows… done in 0s')
            ->expectsOutputToContain('Updating changed shows… done in 0s')
            ->expectsOutputToContain('Reindexing shows… done in 0s');
    });
});

describe('catalog:sync-shows-tmdb marker advancement', function (): void {
    it('advances the shows marker to run-start on a clean default run', function (): void {
        // Arrange
        Cache::flush();
        Date::setTestNow('2026-07-16 12:00:00');
        fakeTmdbShowSync();

        // Act
        $this->artisan('catalog:sync-shows-tmdb');

        // Assert
        expect(Cache::get(SyncFeed::TmdbShows->cacheKey()))->toBe(now()->toIso8601String());
    });

    it('advances the shows marker on a --fresh run', function (): void {
        // Arrange
        Cache::flush();
        Date::setTestNow('2026-07-16 12:00:00');
        fakeTmdbShowSync();

        // Act
        $this->artisan('catalog:sync-shows-tmdb', ['--fresh' => true]);

        // Assert
        expect(Cache::get(SyncFeed::TmdbShows->cacheKey()))->toBe(now()->toIso8601String());
    });

    it('does not advance the shows marker when an insert-phase per-id hydrate fails', function (): void {
        // Arrange
        Cache::flush();
        Exceptions::fake();
        // A held candidate carrying _tmdb_id 500 whose /tv/500 detail 500s persistently;
        // the pool aggregates it as a per-id failure and drops the key from its result,
        // so the insert phase reports failure.
        Show::factory()->withTvdb()->create(['_tmdb_id' => 500, 'tmdb_synced_at' => null]);
        Http::fake([
            '*/tv/changes*' => Http::response('{"results":[],"page":1,"total_pages":1,"total_results":0}'),
            '*api.themoviedb.org*' => fn (Request $request) => Str::endsWith((string) parse_url($request->url(), PHP_URL_PATH), '/tv/500')
                ? Http::response('', 500)
                : Http::response('', 404),
        ]);

        // Act
        $this->artisan('catalog:sync-shows-tmdb');

        // Assert
        expect(Cache::get(SyncFeed::TmdbShows->cacheKey()))->toBeNull();
    });

    it('does not advance the shows marker when a changes-phase re-hydrate fails', function (): void {
        // Arrange
        Cache::flush();
        Exceptions::fake();
        // An already-synced row → a clean insert phase; a locally-held changed id (23310,
        // present in the changes feed) whose /tv/23310 detail 500s persistently makes the
        // CHANGES phase report failure on its own — a distinct failure site from insert.
        Show::factory()->create(['_tmdb_id' => 23310, 'tmdb_synced_at' => now()]);
        Http::fake([
            '*/tv/changes*' => function (Request $request) {
                parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

                return (int) ($query['page'] ?? 1) === 2
                    ? Http::response(fixtureBytes('Catalog/tmdb/tv_changes_page2.json'))
                    : Http::response(fixtureBytes('Catalog/tmdb/tv_changes_page1.json'));
            },
            '*api.themoviedb.org*' => fn (Request $request) => Str::endsWith((string) parse_url($request->url(), PHP_URL_PATH), '/tv/23310')
                ? Http::response('', 500)
                : Http::response('', 404),
        ]);

        // Act
        $this->artisan('catalog:sync-shows-tmdb');

        // Assert
        expect(Cache::get(SyncFeed::TmdbShows->cacheKey()))->toBeNull();
    });
});

describe('catalog:sync-shows-tmdb end-of-leg reindex', function (): void {
    it('reindexes every show the leg touched, exactly once', function (): void {
        // Arrange
        // Both hydrate paths in one run: a direct _tmdb_id row, and an imdb-only row the
        // reconcile stamps before hydrating — a second write to the same row, which the
        // movies leg has no equivalent of. The spy is registered LAST: the Searchable
        // trait syncs on every model save, so a spy installed earlier would also
        // capture the arranged rows' own writes and no row could look un-reindexed.
        $direct = Show::factory()->withTvdb()->create(['_tmdb_id' => 1399, 'tmdb_synced_at' => null]);
        $reconciled = Show::factory()->withTvdb()->create(['_imdb_id' => 'tt0903747', '_tmdb_id' => null, 'tmdb_synced_at' => null]);
        fakeTmdbShowSync();
        $capturedChunks = spyOnScoutEngine();

        // Act
        $this->artisan('catalog:sync-shows-tmdb');

        // Assert
        // Two keys total, both present: each row reached the engine exactly once, however
        // many phases wrote it.
        $reindexed = reindexedIds($capturedChunks());
        expect($reindexed)->toHaveCount(2);
        expect($reindexed)->toContain($direct->id);
        expect($reindexed)->toContain($reconciled->id);
    });

    it('does not reindex a show the leg never touched', function (): void {
        // Arrange
        // _tmdb_id 9000001 is already synced and absent from the (empty) changes feed, so
        // the leg never writes this row. Its updated_at is stamped stale EXPLICITLY: the
        // watermark comparison is `>=` over second-precision timestamps, so a row saved
        // inside the leg's own start second would otherwise sweep in as "touched".
        $touched = Show::factory()->withTvdb()->create(['_tmdb_id' => 1399, 'tmdb_synced_at' => null]);
        $untouched = Show::factory()->create(['_tmdb_id' => 9_000_001, 'tmdb_synced_at' => now()]);
        Show::query()->whereKey($untouched->id)->update(['updated_at' => now()->subDay()]);
        fakeTmdbShowSync();
        $capturedChunks = spyOnScoutEngine();

        // Act
        $this->artisan('catalog:sync-shows-tmdb');

        // Assert
        // Both halves in one test: asserting the absence alone would pass on a run that
        // indexed nothing at all.
        expect(reindexedIds($capturedChunks()))->toContain($touched->id);
        expect(reindexedIds($capturedChunks()))->not->toContain($untouched->id);
    });
});
