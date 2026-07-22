<?php

declare(strict_types=1);

use App\Domains\Catalog\Enums\SyncFeed;
use App\Domains\Catalog\Exceptions\TmdbRequestFailed;
use App\Domains\Catalog\Exceptions\TmdbShowCrosswalkCollision;
use App\Domains\Catalog\Models\Show;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;
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

it('caps hydrated shows with --limit', function (): void {
    // Arrange
    Show::factory()->withTvdb()->create(['_tmdb_id' => 1399, 'tmdb_synced_at' => null]);
    Show::factory()->withTvdb()->create(['_tmdb_id' => 1396, 'tmdb_synced_at' => null]);
    fakeTmdbShowSync();

    // Act
    $this->artisan('catalog:sync-shows-tmdb', ['--limit' => 1]);

    // Assert
    $hydrateCalls = 0;
    Http::assertSent(function (Request $request) use (&$hydrateCalls): bool {
        if (preg_match('#/3/tv/\d+$#', (string) parse_url($request->url(), PHP_URL_PATH))) {
            $hydrateCalls++;
        }

        return true;
    });
    expect($hydrateCalls)->toBe(1);
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

it('requests the changes window from the cached marker with a 6h overlap', function (): void {
    // Arrange
    Cache::flush();
    Date::setTestNow('2026-07-16 12:00:00');
    // Marker at 04:00 (time-of-day < the 6h overlap): marker − 6h crosses to the
    // previous calendar day, so start_date must floor to 2026-07-15. A bare marker
    // (overlap dropped) would floor to today, 2026-07-16, and fail the assertion.
    Cache::put(SyncFeed::TmdbShows->cacheKey(), now()->subHours(8)->toImmutable());
    Show::factory()->create(['_tmdb_id' => 23310, 'tmdb_synced_at' => now()]);
    fakeTmdbShowUpdateSync();

    // Act
    $this->artisan('catalog:sync-shows-tmdb');

    // Assert
    assertRequestedShowChangesWindow('2026-07-15', '2026-07-16');
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

it('skips the update phase with --limit', function (): void {
    // Arrange
    Show::factory()->create(['_tmdb_id' => 23310, 'tmdb_synced_at' => now()]);
    fakeTmdbShowUpdateSync();

    // Act
    $this->artisan('catalog:sync-shows-tmdb', ['--limit' => 1]);

    // Assert
    Http::assertNotSent(fn (Request $request): bool => Str::contains($request->url(), '/tv/changes'));
});

it('reports a persistent changes-feed failure and still exits SUCCESS', function (): void {
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
    $this->artisan('catalog:sync-shows-tmdb')->assertExitCode(0);

    // Assert
    Exceptions::assertReported(TmdbRequestFailed::class);
});

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

it('hydrates every candidate across a set larger than one chunk without skipping rows', function (): void {
    // Arrange
    // One more candidate than BATCH_SIZE (1000), so the run spans two chunkById
    // pages. Each row carries a distinct _tmdb_id; hydration stamps
    // tmdb_synced_at, the very column the default run filters on — proving PK
    // pagination doesn't skip rows whose filtered column mutates mid-iteration.
    $rows = [];
    for ($i = 0; $i < 1001; $i++) {
        $rows[] = ['_tmdb_id' => 500_000 + $i, '_imdb_id' => 'tt'.str_pad((string) (8_000_000 + $i), 7, '0', STR_PAD_LEFT)];
    }
    Show::insert($rows);
    // Decode the detail fixture once and drop its images block: this test proves
    // chunkById spans two pages without skipping rows, not image persistence.
    // Keeping the 643-entry images block would fire ~643k media upserts across
    // the 1001 rows and exhaust memory.
    $body = json_decode(fixtureBytes('Catalog/tmdb/tv.json'), true);
    unset($body['images']);
    Http::fake([
        '*/tv/changes*' => Http::response('{"results":[],"page":1,"total_pages":1,"total_results":0}'),
        '*api.themoviedb.org*' => function (Request $request) use ($body) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            if (preg_match('#/tv/(\d+)$#', $path, $matches) === 1) {
                $body['id'] = (int) $matches[1];

                return Http::response(json_encode($body));
            }

            return Http::response('', 404);
        },
    ]);

    // Act
    $this->artisan('catalog:sync-shows-tmdb');

    // Assert
    expect(Show::whereNull('tmdb_synced_at')->count())->toBe(0);
    expect(Show::count())->toBe(1001);
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

it('advances the shows marker to run-start on a clean default run', function (): void {
    // Arrange
    Cache::flush();
    Date::setTestNow('2026-07-16 12:00:00');
    fakeTmdbShowSync();

    // Act
    $this->artisan('catalog:sync-shows-tmdb');

    // Assert
    expect(Cache::get(SyncFeed::TmdbShows->cacheKey())->equalTo(now()))->toBeTrue();
});

it('advances the shows marker on a --fresh run', function (): void {
    // Arrange
    Cache::flush();
    Date::setTestNow('2026-07-16 12:00:00');
    fakeTmdbShowSync();

    // Act
    $this->artisan('catalog:sync-shows-tmdb', ['--fresh' => true]);

    // Assert
    expect(Cache::get(SyncFeed::TmdbShows->cacheKey())->equalTo(now()))->toBeTrue();
});

it('does not advance the shows marker on a --limit run', function (): void {
    // Arrange
    Cache::flush();
    fakeTmdbShowSync();

    // Act
    $this->artisan('catalog:sync-shows-tmdb', ['--limit' => 1]);

    // Assert
    expect(Cache::get(SyncFeed::TmdbShows->cacheKey()))->toBeNull();
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
