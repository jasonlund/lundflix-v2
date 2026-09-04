<?php

declare(strict_types=1);

use App\Domains\Catalog\Actions\RefreshTvdbEpisodes;
use App\Domains\Catalog\Models\Episode;
use App\Domains\Catalog\Models\Season;
use App\Domains\Catalog\Models\Show;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Fixtures (byte-exact real TheTVDB v4 slices) — targeted episode refresh
|--------------------------------------------------------------------------
| RefreshTvdbEpisodes takes the already-seeded shows an /updates?type=episodes
| window touched (keyed by `_tvdb_id`) plus the changed episode ids belonging
| to them, fetches ONLY those episodes through TvdbApiService::episodesMany()
| (GET /episodes/{id}, pooled), attributes each to the show its own payload's
| seriesId names, re-derives that show's season links, re-stamps its
| episodes_synced_at, and returns an EpisodeRefreshResult { episodes,
| failedEpisodes }. It never crawls /series/{id}/episodes — that whole-catalog
| re-walk is the leg this replaces, so its absence is asserted directly.
|
| tests/Fixtures/Catalog/tvdb/episode_{id}.json — real GET /episodes/{id}
|   bodies, the full {"status":"success","data":{…}} envelope:
|     9786562, 9786563  → seriesId 434847, seasonNumber 1
|     11846050, 11846051 → seriesId 469484, seasonNumber 1
|     9256455, 9256456   → seriesId 371082, seasonNumber 3
|   Each id has its own capture, so an episode mis-attributed to the wrong show
|   cannot pass by carrying an identical payload.
| login.json — POST /login → data.token JWT.
|
| Http::preventStrayRequests() is GLOBAL and the JWT is fetched before any
| /episodes call, so EVERY fake map below also answers the login endpoint or
| the test fails for the wrong reason. Cache::flush() stops the long-lived JWT
| bleeding across tests; config() sets the apikey the login exchanges.
*/

/**
 * The `$shows` argument built exactly the way the sync command builds it — the
 * narrowed select, the seeded-only filter, keyed by `_tvdb_id` — so the tests
 * exercise the real keying rather than a hand-built map.
 *
 * @param  list<int>  $tvdbIds
 * @return Collection<int, Show>
 */
function showsKeyedForEpisodeRefresh(array $tvdbIds): Collection
{
    return Show::query()
        ->select(['id', '_tvdb_id', '_tvdb_defaultSeasonType'])
        ->whereIn('_tvdb_id', $tvdbIds)
        ->whereNotNull('episodes_synced_at')
        ->get()
        ->keyBy('_tvdb_id');
}

beforeEach(function (): void {
    Cache::flush();
    config(['services.tvdb.key' => 'test-key']);
});

describe('handle() targeted episode fetching', function (): void {
    it('fetches only the named episode ids and persists them', function (): void {
        // Arrange
        $show = Show::factory()->create([
            '_tvdb_id' => 434847,
            '_tvdb_defaultSeasonType' => 1,
            'episodes_synced_at' => now(),
        ]);
        Http::fake([
            '*api4.thetvdb.com/v4/login*' => Http::response(fixtureBytes('Catalog/tvdb/login.json')),
            '*/episodes/9786562*' => Http::response(fixtureBytes('Catalog/tvdb/episode_9786562.json')),
            '*/episodes/9786563*' => Http::response(fixtureBytes('Catalog/tvdb/episode_9786563.json')),
        ]);

        // Act
        $result = resolve(RefreshTvdbEpisodes::class)->handle(
            showsKeyedForEpisodeRefresh([434847]),
            collect([9786562, 9786563]),
        );

        // Assert
        expect($result->episodes)->toBe(2)
            ->and($result->failedEpisodes)->toBe(0)
            ->and($show->episodes()->pluck('_tvdb_id')->sort()->values()->all())->toBe([9786562, 9786563]);
        Http::assertSent(fn ($request): bool => Str::contains((string) $request->url(), '/episodes/9786562'));
        Http::assertSent(fn ($request): bool => Str::contains((string) $request->url(), '/episodes/9786563'));
        // The acceptance criterion of the ticket: the whole-catalog per-show
        // episode crawl is gone, so no /series/ request may be made at all.
        Http::assertNotSent(fn ($request): bool => Str::contains((string) $request->url(), '/series/'));
    });
});

describe('handle() show attribution', function (): void {
    it('attributes each episode to the show its own payload names', function (): void {
        // Arrange
        $firstShow = Show::factory()->create([
            '_tvdb_id' => 434847,
            '_tvdb_defaultSeasonType' => 1,
            'episodes_synced_at' => now(),
        ]);
        $secondShow = Show::factory()->create([
            '_tvdb_id' => 469484,
            '_tvdb_defaultSeasonType' => 1,
            'episodes_synced_at' => now(),
        ]);
        Http::fake([
            '*api4.thetvdb.com/v4/login*' => Http::response(fixtureBytes('Catalog/tvdb/login.json')),
            '*/episodes/9786562*' => Http::response(fixtureBytes('Catalog/tvdb/episode_9786562.json')),
            '*/episodes/9786563*' => Http::response(fixtureBytes('Catalog/tvdb/episode_9786563.json')),
            '*/episodes/11846050*' => Http::response(fixtureBytes('Catalog/tvdb/episode_11846050.json')),
            '*/episodes/11846051*' => Http::response(fixtureBytes('Catalog/tvdb/episode_11846051.json')),
        ]);

        // Act
        resolve(RefreshTvdbEpisodes::class)->handle(
            showsKeyedForEpisodeRefresh([434847, 469484]),
            collect([9786562, 11846050, 9786563, 11846051]),
        );

        // Assert
        expect($firstShow->episodes()->pluck('_tvdb_id')->sort()->values()->all())->toBe([9786562, 9786563])
            ->and($secondShow->episodes()->pluck('_tvdb_id')->sort()->values()->all())->toBe([11846050, 11846051]);
    });

    it('ignores an episode whose series is not among the given shows', function (): void {
        // Arrange
        // Series 371082 has no Show row at all, so the episodes it owns belong to
        // nothing this run may write — they must not fall through to the one show
        // that IS present.
        $show = Show::factory()->create([
            '_tvdb_id' => 434847,
            '_tvdb_defaultSeasonType' => 1,
            'episodes_synced_at' => now(),
        ]);
        Http::fake([
            '*api4.thetvdb.com/v4/login*' => Http::response(fixtureBytes('Catalog/tvdb/login.json')),
            '*/episodes/9786562*' => Http::response(fixtureBytes('Catalog/tvdb/episode_9786562.json')),
            '*/episodes/9256455*' => Http::response(fixtureBytes('Catalog/tvdb/episode_9256455.json')),
            '*/episodes/9256456*' => Http::response(fixtureBytes('Catalog/tvdb/episode_9256456.json')),
        ]);

        // Act
        $result = resolve(RefreshTvdbEpisodes::class)->handle(
            showsKeyedForEpisodeRefresh([434847]),
            collect([9786562, 9256455, 9256456]),
        );

        // Assert
        expect($result->episodes)->toBe(1)
            ->and($show->episodes()->pluck('_tvdb_id')->sort()->values()->all())->toBe([9786562]);
        $this->assertDatabaseMissing('episodes', ['_tvdb_id' => 9256455]);
        $this->assertDatabaseMissing('episodes', ['_tvdb_id' => 9256456]);
    });
});

describe('handle() season links and show stamping', function (): void {
    it('re-derives season links and stamps episodes_synced_at only for the shows it touched', function (): void {
        // Arrange
        // Both stamps start a day behind run-start so the re-stamp on the touched
        // show, and the absence of one on the untouched show, are both observable.
        Date::setTestNow('2026-07-16 12:00:00');
        $touchedShow = Show::factory()->create([
            '_tvdb_id' => 434847,
            '_tvdb_defaultSeasonType' => 1,
            'episodes_synced_at' => now()->subDay(),
        ]);
        $untouchedShow = Show::factory()->create([
            '_tvdb_id' => 469484,
            '_tvdb_defaultSeasonType' => 1,
            'episodes_synced_at' => now()->subDay(),
        ]);
        $season = Season::factory()->create([
            'show_id' => $touchedShow->id,
            '_tvdb_number' => 1,
            '_tvdb_type' => ['id' => 1, 'name' => 'Aired Order', 'type' => 'official'],
        ]);
        Http::fake([
            '*api4.thetvdb.com/v4/login*' => Http::response(fixtureBytes('Catalog/tvdb/login.json')),
            '*/episodes/9786562*' => Http::response(fixtureBytes('Catalog/tvdb/episode_9786562.json')),
        ]);

        // Act
        resolve(RefreshTvdbEpisodes::class)->handle(
            showsKeyedForEpisodeRefresh([434847, 469484]),
            collect([9786562]),
        );

        // Assert
        expect(Episode::where('_tvdb_id', 9786562)->value('season_id'))->toBe($season->id)
            ->and($touchedShow->fresh()->episodes_synced_at->toDateTimeString())->toBe('2026-07-16 12:00:00')
            ->and($untouchedShow->fresh()->episodes_synced_at->toDateTimeString())->toBe('2026-07-15 12:00:00');
    });
});

describe('handle() per-id fetch outcomes', function (): void {
    it('counts a per-id fetch failure without dropping the batch good episodes', function (): void {
        // Arrange
        // The pooling concern retries then report()s one aggregate TvdbRequestFailed,
        // so the backoff sleeps and the reported exception are both faked away.
        Sleep::fake();
        Exceptions::fake();
        $show = Show::factory()->create([
            '_tvdb_id' => 371082,
            '_tvdb_defaultSeasonType' => 1,
            'episodes_synced_at' => now(),
        ]);
        Http::fake([
            '*api4.thetvdb.com/v4/login*' => Http::response(fixtureBytes('Catalog/tvdb/login.json')),
            '*/episodes/9256455*' => Http::response('', 500),
            '*/episodes/9256456*' => Http::response(fixtureBytes('Catalog/tvdb/episode_9256456.json')),
        ]);

        // Act
        $result = resolve(RefreshTvdbEpisodes::class)->handle(
            showsKeyedForEpisodeRefresh([371082]),
            collect([9256455, 9256456]),
        );

        // Assert
        expect($result->episodes)->toBe(1)
            ->and($result->failedEpisodes)->toBe(1)
            ->and($show->episodes()->pluck('_tvdb_id')->all())->toBe([9256456]);
    });

    it('treats a 404 episode as a miss, not a failure', function (): void {
        // Arrange
        // TheTVDB 404s an episode it has deleted since the feed named it — a settled
        // upstream answer, not a fetch that needs re-covering next run.
        $show = Show::factory()->create([
            '_tvdb_id' => 434847,
            '_tvdb_defaultSeasonType' => 1,
            'episodes_synced_at' => now(),
        ]);
        Http::fake([
            '*api4.thetvdb.com/v4/login*' => Http::response(fixtureBytes('Catalog/tvdb/login.json')),
            '*/episodes/9786562*' => Http::response('', 404),
            '*/episodes/9786563*' => Http::response(fixtureBytes('Catalog/tvdb/episode_9786563.json')),
        ]);

        // Act
        $result = resolve(RefreshTvdbEpisodes::class)->handle(
            showsKeyedForEpisodeRefresh([434847]),
            collect([9786562, 9786563]),
        );

        // Assert
        expect($result->failedEpisodes)->toBe(0)
            ->and($result->episodes)->toBe(1)
            ->and($show->episodes()->pluck('_tvdb_id')->all())->toBe([9786563]);
        $this->assertDatabaseMissing('episodes', ['_tvdb_id' => 9786562]);
    });
});
