<?php

declare(strict_types=1);

use App\Domains\Catalog\Enums\SyncFeed;
use App\Domains\Catalog\Models\Show;
use App\Domains\Catalog\Support\SyncMarker;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Fixtures (byte-exact real TheTVDB v4 slices)
|--------------------------------------------------------------------------
| catalog:sync-shows-tvdb is updates-only — it hydrates ids from the /updates feed
| since the feed's persisted marker (6h overlap, 24h no-marker fallback, 14-day cap)
| and upserts them, advancing the marker after a clean run. No crawl, no
| --fresh, no skip-synced.
|
| tests/Fixtures/Catalog/tvdb/login.json — POST /login → data.token JWT;
|   every fake map answers it because Http::preventStrayRequests() is global
|   and the JWT is fetched (and cached) before any /updates call.
| tests/Fixtures/Catalog/tvdb/updates.json + updates_page2.json — the /updates
|   feed, chained p0 → p1 → null via links.next; each record's series id is
|   `recordId` (recordIds 434847, 469484, 372030, then 470158, 371782, 479253).
| tests/Fixtures/Catalog/tvdb/series_extended.json — GET /series/{id}/extended
|   (wrapped {status,data}); the extended Breaking Bad payload, data.id 81189,
|   data.name 'Breaking Bad', data.artworks 343 entries (109 mapped to media).
|
| 81189 is in NONE of the updates fixtures, so the fake serves the extended
| payload for exactly ONE discovered id and 404s every other /extended
| (mirroring SyncTmdbMovies serving /movie/603 only) — that one success upserts
| the show as _tvdb_id 81189. The updates fake serves it for update id 434847.
*/

function fakeTvdbUpdates(): void
{
    Http::fake([
        '*api4.thetvdb.com/v4/login*' => Http::response(fixtureBytes('Catalog/tvdb/login.json')),
        '*api4.thetvdb.com/v4/series/*/extended*' => fn (Request $request) => Str::contains($request->url(), '/series/434847/extended')
            ? Http::response(fixtureBytes('Catalog/tvdb/series_extended.json'))
            : Http::response('', 404),
        '*api4.thetvdb.com/v4/updates*' => fn (Request $request) => Str::contains($request->url(), 'page=1')
            ? Http::response(fixtureBytes('Catalog/tvdb/updates_page2.json'))
            : Http::response(fixtureBytes('Catalog/tvdb/updates.json')),
    ]);
}

beforeEach(function (): void {
    Cache::flush();
    config(['services.tvdb.key' => 'test-key']);
});

describe('catalog:sync-shows-tvdb updates-feed hydration and marker', function (): void {
    it('hydrates ids from the updates feed and persists them', function (): void {
        // Arrange
        fakeTvdbUpdates();

        // Act
        $this->artisan('catalog:sync-shows-tvdb');

        // Assert
        expect(Show::where('_tvdb_id', 81189)->first())->not->toBeNull();
    });

    it('queries /updates with since = the cached marker minus a 6h overlap', function (): void {
        // Arrange
        Date::setTestNow('2026-07-16 12:00:00');
        $marker = now()->subHours(10)->toImmutable();
        resolve(SyncMarker::class)->advance(SyncFeed::TvdbShows, $marker);
        fakeTvdbUpdates();

        // Act
        $this->artisan('catalog:sync-shows-tvdb');

        // Assert
        Http::assertSent(fn (Request $request): bool => Str::contains(urldecode((string) $request->url()), 'since='.$marker->subHours(6)->timestamp));
    });

    it('queries /updates with since = now minus 24h when no marker is cached', function (): void {
        // Arrange
        Date::setTestNow('2026-07-16 12:00:00');
        fakeTvdbUpdates();

        // Act
        $this->artisan('catalog:sync-shows-tvdb');

        // Assert
        Http::assertSent(fn (Request $request): bool => Str::contains(urldecode((string) $request->url()), 'since='.now()->subHours(24)->timestamp));
    });

    it('advances the marker to run-start after a clean run', function (): void {
        // Arrange
        Date::setTestNow('2026-07-16 12:00:00');
        fakeTvdbUpdates();

        // Act
        $this->artisan('catalog:sync-shows-tvdb');

        // Assert
        expect(Cache::get(SyncFeed::TvdbShows->cacheKey()))->toBe(now()->toIso8601String());
    });

    it('does not advance the marker when a hydrate fails', function (): void {
        // Arrange
        Date::setTestNow('2026-07-16 12:00:00');
        Http::fake([
            '*api4.thetvdb.com/v4/login*' => Http::response(fixtureBytes('Catalog/tvdb/login.json')),
            '*api4.thetvdb.com/v4/series/*/extended*' => fn (Request $request) => Str::contains($request->url(), '/series/434847/extended')
                ? Http::response('', 500)
                : Http::response('', 404),
            '*api4.thetvdb.com/v4/updates*' => fn (Request $request) => Str::contains($request->url(), 'page=1')
                ? Http::response(fixtureBytes('Catalog/tvdb/updates_page2.json'))
                : Http::response(fixtureBytes('Catalog/tvdb/updates.json')),
        ]);

        // Act
        $this->artisan('catalog:sync-shows-tvdb');

        // Assert
        expect(Cache::get(SyncFeed::TvdbShows->cacheKey()))->toBeNull();
    });

    it('does not advance the marker when a whole chunk fails', function (): void {
        // Arrange
        // Deliberate pair with the per-id test above: the marker must hold on BOTH failure
        // paths. That one covers a pooled per-id miss; this one covers syncChunkSafely()'s
        // catch — a 401 forgets the JWT and throws out of the pool, failing the whole chunk,
        // and those chunk-wide ids are exactly the ones the marker gate depends on.
        Date::setTestNow('2026-07-16 12:00:00');
        Http::fake([
            '*api4.thetvdb.com/v4/login*' => Http::response(fixtureBytes('Catalog/tvdb/login.json')),
            '*api4.thetvdb.com/v4/series/*/extended*' => Http::response('', 401),
            '*api4.thetvdb.com/v4/updates*' => fn (Request $request) => Str::contains($request->url(), 'page=1')
                ? Http::response(fixtureBytes('Catalog/tvdb/updates_page2.json'))
                : Http::response(fixtureBytes('Catalog/tvdb/updates.json')),
        ]);

        // Act
        $this->artisan('catalog:sync-shows-tvdb');

        // Assert
        expect(Cache::get(SyncFeed::TvdbShows->cacheKey()))->toBeNull();
    });
});

describe('catalog:sync-shows-tvdb feed scope and run output', function (): void {
    it('uses the updates feed only and never crawls /series?page', function (): void {
        // Arrange
        fakeTvdbUpdates();

        // Act
        $this->artisan('catalog:sync-shows-tvdb');

        // Assert
        Http::assertSent(fn (Request $request): bool => Str::contains($request->url(), '/updates'));
        Http::assertNotSent(fn (Request $request): bool => Str::contains($request->url(), '/series?page'));
    });

    it('re-hydrates an already-synced show that appears in the window', function (): void {
        // Arrange
        Show::factory()->create(['_tvdb_id' => 434847, 'tvdb_synced_at' => now()]);
        fakeTvdbUpdates();

        // Act
        $this->artisan('catalog:sync-shows-tvdb');

        // Assert
        Http::assertSent(fn (Request $request): bool => Str::contains($request->url(), '/series/434847/extended'));
    });

    it('exits SUCCESS', function (): void {
        // Arrange
        fakeTvdbUpdates();

        // Act & Assert
        $this->artisan('catalog:sync-shows-tvdb')->assertExitCode(0);
    });

    it('announces it is starting before the pipeline runs', function (): void {
        // Arrange
        fakeTvdbUpdates();

        // Act & Assert
        $this->artisan('catalog:sync-shows-tvdb')->expectsOutputToContain('Syncing shows…');
    });
});

describe('catalog:sync-shows-tvdb run-closing output', function (): void {
    it('reports its exact final count on a run that never reaches the beat interval', function (): void {
        // Arrange
        // The happy-path fake hydrates exactly one id (434847 → the extended payload) and
        // 404s every other update, so the run upserts one payload — far short of the
        // 1000-payload beat interval, which is why nothing is printed today. The count is
        // pinned to the observed run, not to the interval arithmetic.
        fakeTvdbUpdates();

        // Act & Assert
        $this->artisan('catalog:sync-shows-tvdb')->expectsOutputToContain('  [tvdb shows 1]');
    });

    it('ends the run with a Done. line', function (): void {
        // Arrange
        fakeTvdbUpdates();

        // Act & Assert
        $this->artisan('catalog:sync-shows-tvdb')->expectsOutputToContain('Done.');
    });

    it('closes the run with the failed show count and the marker consequence, exiting FAILURE', function (): void {
        // Arrange
        // The marker-hold fake: /series/434847/extended 500s persistently, so the pool
        // aggregates exactly one failed id and drops it from its results; every other
        // update 404s, which stays present-as-null and never counts as a failure.
        Date::setTestNow('2026-07-16 12:00:00');
        Http::fake([
            '*api4.thetvdb.com/v4/login*' => Http::response(fixtureBytes('Catalog/tvdb/login.json')),
            '*api4.thetvdb.com/v4/series/*/extended*' => fn (Request $request) => Str::contains($request->url(), '/series/434847/extended')
                ? Http::response('', 500)
                : Http::response('', 404),
            '*api4.thetvdb.com/v4/updates*' => fn (Request $request) => Str::contains($request->url(), 'page=1')
                ? Http::response(fixtureBytes('Catalog/tvdb/updates_page2.json'))
                : Http::response(fixtureBytes('Catalog/tvdb/updates.json')),
        ]);

        // Act & Assert
        $this->artisan('catalog:sync-shows-tvdb')
            ->expectsOutputToContain('1 shows failed; marker not advanced.')
            ->doesntExpectOutputToContain('  1 shows failed')
            ->assertExitCode(Command::FAILURE);
    });
});

describe('catalog:sync-shows-tvdb season persistence', function (): void {
    it('persists the hydrated show\'s seasons linked by show_id', function (): void {
        // Arrange
        fakeTvdbUpdates();

        // Act
        $this->artisan('catalog:sync-shows-tvdb');

        // Assert
        $show = Show::where('_tvdb_id', 81189)->firstOrFail();
        expect($show->seasons()->count())->toBe(13);
        $this->assertDatabaseHas('seasons', ['show_id' => $show->id, '_tvdb_id' => 30272, '_tvdb_number' => 1]);
    });

    it('makes no extra HTTP call beyond the existing /extended hydration for seasons', function (): void {
        // Arrange
        fakeTvdbUpdates();

        // Act
        $this->artisan('catalog:sync-shows-tvdb');

        // Assert
        Http::assertNotSent(fn (Request $request): bool => Str::contains($request->url(), '/seasons'));
    });
});

/*
|--------------------------------------------------------------------------
| End-of-leg reindex
|--------------------------------------------------------------------------
| The ingest upserts through `Show::upsert()`, which fires no model events, so
| nothing is indexed during the leg; the leg reindexes exactly the rows it
| touched (updated_at >= run start) once, after ingest. Every test below freezes
| the clock, which both pins the elapsed phase lines at `0s` and makes the
| run-start watermark exactly equal to the `updated_at` the upsert writes.
*/

/**
 * Stamp a row's `updated_at` without the model touching timestamps itself.
 */
$stampUpdatedAt = function (Show $row, CarbonImmutable $updatedAt): void {
    $row->newQuery()->whereKey($row->getKey())->update(['updated_at' => $updatedAt]);
};

describe('catalog:sync-shows-tvdb end-of-leg reindex', function () use ($stampUpdatedAt): void {
    it('passes exactly the shows touched by the leg to the engine, once, at end of leg', function () use ($stampUpdatedAt): void {
        // Arrange
        // 9_000_001 is in neither the updates feed nor the extended payload, so this row
        // is never re-upserted — only its stale updated_at keeps it out of the reindex.
        Date::setTestNow('2026-07-16 12:00:00');
        $stale = Show::factory()->withTvdb()->create(['_tvdb_id' => 9_000_001]);
        $stampUpdatedAt($stale, CarbonImmutable::now()->subDay());
        fakeTvdbUpdates();
        $capturedChunks = spyOnScoutEngine();

        // Act
        $this->artisan('catalog:sync-shows-tvdb')->run();

        // Assert
        $touched = Show::query()->where('_tvdb_id', 81189)->firstOrFail();
        expect($capturedChunks())->toBe([[$touched->id]]);
    });

    it('emits the reindex heartbeat in the command output', function (): void {
        // Arrange
        Date::setTestNow('2026-07-16 12:00:00');
        fakeTvdbUpdates();

        // Act & Assert
        $this->artisan('catalog:sync-shows-tvdb')->expectsOutputToContain('  [reindex 1]');
    });

    it('prints the reindex phase lines with elapsed time', function (): void {
        // Arrange
        Date::setTestNow('2026-07-16 12:00:00');
        fakeTvdbUpdates();

        // Act & Assert
        $this->artisan('catalog:sync-shows-tvdb')
            ->expectsOutputToContain('Reindexing shows…')
            ->expectsOutputToContain('Reindexed 1 show in 0s');
    });

    it('prints the reindex phase lines in queued wording when scout queues its index writes', function (): void {
        // Production runs SCOUT_QUEUE=true, where the phase only DISPATCHES the index
        // writes — its elapsed seconds time the dispatch, not the indexing, so the
        // lines must not claim the shows were indexed.
        // Arrange
        Date::setTestNow('2026-07-16 12:00:00');
        config(['scout.queue' => true]);
        Queue::fake();
        fakeTvdbUpdates();

        // Act
        Artisan::call('catalog:sync-shows-tvdb');

        // Assert
        expect(Artisan::output())
            ->toContain('Queueing shows for reindex…')
            ->toContain('  [reindex 1 queued]')
            ->toContain('Queued 1 show for reindex in 0s');
    });

    it('prints the ingest completion line with elapsed time', function (): void {
        // Arrange
        Date::setTestNow('2026-07-16 12:00:00');
        fakeTvdbUpdates();

        // Act & Assert
        $this->artisan('catalog:sync-shows-tvdb')->expectsOutputToContain('Synced shows in 0s');
    });

    it('still reindexes the touched rows when a later hydrate fails', function (): void {
        // Arrange
        // Inverted twin of the marker-hold fake above: 434847 hydrates, every other id
        // 500s, so the leg both persists one show and reports a failure.
        Date::setTestNow('2026-07-16 12:00:00');
        Http::fake([
            '*api4.thetvdb.com/v4/login*' => Http::response(fixtureBytes('Catalog/tvdb/login.json')),
            '*api4.thetvdb.com/v4/series/*/extended*' => fn (Request $request) => Str::contains($request->url(), '/series/434847/extended')
                ? Http::response(fixtureBytes('Catalog/tvdb/series_extended.json'))
                : Http::response('', 500),
            '*api4.thetvdb.com/v4/updates*' => fn (Request $request) => Str::contains($request->url(), 'page=1')
                ? Http::response(fixtureBytes('Catalog/tvdb/updates_page2.json'))
                : Http::response(fixtureBytes('Catalog/tvdb/updates.json')),
        ]);
        $capturedChunks = spyOnScoutEngine();

        // Act
        $this->artisan('catalog:sync-shows-tvdb')->run();

        // Assert
        $touched = Show::query()->where('_tvdb_id', 81189)->firstOrFail();
        expect($capturedChunks())->toBe([[$touched->id]]);
    });

    it('a window that hydrates nothing still prints every phase line and sends nothing to the engine', function (): void {
        // Arrange
        // Every /extended 404s: a quiet window, not a failed one — nothing is upserted
        // yet the leg still runs its reindex phase and exits clean.
        Date::setTestNow('2026-07-16 12:00:00');
        Http::fake([
            '*api4.thetvdb.com/v4/login*' => Http::response(fixtureBytes('Catalog/tvdb/login.json')),
            '*api4.thetvdb.com/v4/series/*/extended*' => Http::response('', 404),
            '*api4.thetvdb.com/v4/updates*' => fn (Request $request) => Str::contains($request->url(), 'page=1')
                ? Http::response(fixtureBytes('Catalog/tvdb/updates_page2.json'))
                : Http::response(fixtureBytes('Catalog/tvdb/updates.json')),
        ]);
        $capturedChunks = spyOnScoutEngine();

        // Act
        $exitCode = Artisan::call('catalog:sync-shows-tvdb');

        // Assert
        expect(Artisan::output())->toContain('Syncing shows…')
            ->toContain('Synced shows in 0s')
            ->toContain('Reindexing shows…')
            ->toContain('Reindexed 0 shows in 0s')
            ->and($capturedChunks())->toBe([])
            ->and($exitCode)->toBe(0);
    });
});
