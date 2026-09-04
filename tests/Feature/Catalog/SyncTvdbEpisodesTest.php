<?php

declare(strict_types=1);

use App\Domains\Catalog\Enums\SyncFeed;
use App\Domains\Catalog\Exceptions\TvdbRequestFailed;
use App\Domains\Catalog\Models\Show;
use App\Domains\Catalog\Support\SyncMarker;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Fixtures (byte-exact real TheTVDB v4 slices)
|--------------------------------------------------------------------------
| catalog:sync-episodes-tvdb pulls the /updates?type=episodes feed since the
| TvdbEpisodes marker (6h overlap, 24h no-marker fallback, 14d cap), keeps each
| record's `recordId` (the changed episode) AND `seriesId`, resolves the series
| that already have a seeded show (episodes_synced_at not null), and hands those
| shows' changed episode ids to RefreshTvdbEpisodes — which fetches ONLY those
| ids via GET /episodes/{id}. The whole-catalog /series/{id}/episodes crawl is
| gone, so its absence is asserted directly.
|
| tests/Fixtures/Catalog/tvdb/login.json — POST /login → data.token JWT;
|   every fake map answers it because Http::preventStrayRequests() is global.
| tests/Fixtures/Catalog/tvdb/episode_updates.json + episode_updates_page2.json —
|   the /updates?type=episodes feed, chained p0 → p1 → null via links.next:
|     page 0: recordId 9786562, 9786563   → seriesId 434847
|             recordId 11846050, 11846051 → seriesId 469484
|     page 1: recordId 9256455, 9256456   → seriesId 371082
|   So one seeded show 434847 yields exactly 2 episodes; all three seeded, 6.
| tests/Fixtures/Catalog/tvdb/episode_{recordId}.json — a real GET /episodes/{id}
|   body per id above, the full {"status":"success","data":{…}} envelope
|   (seasonNumber 1 for 434847/469484, 3 for 371082). Each id has its own
|   capture, so an episode fetched for the wrong show cannot pass unnoticed.
|
| The /updates walk pages via `links.next` ending in `&page=1`, so the feed fake
| branches page=1 → its own page 2, while the per-episode fake keys off the id
| trailing the request URL.
*/

function fakeTvdbEpisodes(): void
{
    Http::fake([
        '*api4.thetvdb.com/v4/login*' => Http::response(fixtureBytes('Catalog/tvdb/login.json')),
        '*api4.thetvdb.com/v4/updates*' => fn (Request $request) => Str::contains($request->url(), 'page=1')
            ? Http::response(fixtureBytes('Catalog/tvdb/episode_updates_page2.json'))
            : Http::response(fixtureBytes('Catalog/tvdb/episode_updates.json')),
        '*api4.thetvdb.com/v4/episodes/*' => fn (Request $request) => Http::response(
            fixtureBytes('Catalog/tvdb/episode_'.Str::afterLast($request->url(), '/').'.json'),
        ),
    ]);
}

/**
 * The `select` statements against `shows` captured in the query log, so the
 * membership lookup can be told apart from the run's other reads (seasons,
 * episodes). Returns the raw log entries (`query` + `bindings`), which the
 * assertions read as unquoted substrings and binding counts.
 *
 * @return list<array{query: string, bindings: array<int, mixed>}>
 */
function loggedShowSelects(): array
{
    return loggedStatements(fn (string $sql): bool => Str::startsWith($sql, 'select')
        && Str::contains($sql, 'shows'))->all();
}

/**
 * One /updates record in TheTVDB's real wire shape, varying only the ids. Both
 * ids are `mixed` because the malformed-record tests need to hand each of them
 * free text, which is what a real feed occasionally ships.
 *
 * @return array<string, mixed>
 */
function tvdbEpisodeUpdateRecord(mixed $recordId, mixed $seriesId): array
{
    return [
        'recordType' => '',
        'recordId' => $recordId,
        'methodInt' => 2,
        'method' => 'update',
        'extraInfo' => '',
        'userId' => 0,
        'timeStamp' => 1781503201,
        'seriesId' => $seriesId,
        'entityType' => 'episodes',
    ];
}

/**
 * The happy-path fakes with every per-episode fetch 500ing: the feed itself
 * still drains cleanly, so each changed episode id the run resolves fails on its
 * own fetch and the run ends with failures to report.
 */
function fakeTvdbEpisodesWithFailingEpisodeFetch(): void
{
    Http::fake([
        '*api4.thetvdb.com/v4/login*' => Http::response(fixtureBytes('Catalog/tvdb/login.json')),
        '*api4.thetvdb.com/v4/updates*' => fn (Request $request) => Str::contains($request->url(), 'page=1')
            ? Http::response(fixtureBytes('Catalog/tvdb/episode_updates_page2.json'))
            : Http::response(fixtureBytes('Catalog/tvdb/episode_updates.json')),
        '*api4.thetvdb.com/v4/episodes/*' => Http::response('', 500),
    ]);
}

beforeEach(function (): void {
    Cache::flush();
    config(['services.tvdb.key' => 'test-key']);
});

describe('catalog:sync-episodes-tvdb feed hydration and marker window', function (): void {
    it('hydrates only the changed episodes of a seeded show that appears in the episodes feed', function (): void {
        // Arrange
        fakeTvdbEpisodes();
        Show::factory()->create(['_tvdb_id' => 434847, 'episodes_synced_at' => now(), '_tvdb_defaultSeasonType' => 1]);

        // Act
        $this->artisan('catalog:sync-episodes-tvdb');

        // Assert
        // The feed names exactly two changed episodes for 434847, and that is the
        // whole cost of the run: the show's other episodes are never touched, and
        // the whole-catalog /series/{id}/episodes crawl is not made at all.
        $this->assertDatabaseCount('episodes', 2);
        $this->assertDatabaseHas('episodes', ['_tvdb_id' => 9786562]);
        $this->assertDatabaseHas('episodes', ['_tvdb_id' => 9786563]);
        Http::assertNotSent(fn (Request $request): bool => Str::contains($request->url(), '/series/'));
    });

    it('queries /updates with type=episodes and since = now minus 24h when no marker is cached', function (): void {
        // Arrange
        Date::setTestNow('2026-07-16 12:00:00');
        fakeTvdbEpisodes();

        // Act
        $this->artisan('catalog:sync-episodes-tvdb');

        // Assert
        Http::assertSent(fn (Request $request): bool => Str::contains(urldecode((string) $request->url()), 'since='.now()->subHours(24)->timestamp)
            && Str::contains($request->url(), 'type=episodes'));
    });

    it('queries /updates with since = the cached marker minus a 6h overlap', function (): void {
        // Arrange
        Date::setTestNow('2026-07-16 12:00:00');
        $marker = now()->subHours(10)->toImmutable();
        resolve(SyncMarker::class)->advance(SyncFeed::TvdbEpisodes, $marker);
        fakeTvdbEpisodes();

        // Act
        $this->artisan('catalog:sync-episodes-tvdb');

        // Assert
        Http::assertSent(fn (Request $request): bool => Str::contains(urldecode((string) $request->url()), 'since='.$marker->subHours(6)->timestamp));
    });

    it('advances the marker to run-start after a clean run', function (): void {
        // Arrange
        Date::setTestNow('2026-07-16 12:00:00');
        fakeTvdbEpisodes();
        Show::factory()->create(['_tvdb_id' => 434847, 'episodes_synced_at' => now(), '_tvdb_defaultSeasonType' => 1]);

        // Act
        $this->artisan('catalog:sync-episodes-tvdb');

        // Assert
        expect(Cache::get(SyncFeed::TvdbEpisodes->cacheKey()))->toBe(now()->toIso8601String());
    });

    it('does not advance the marker when a changed episode fetch fails', function (): void {
        // Arrange
        // The pooled fetch retries through the global retry middleware before it
        // gives up on an id, so the backoff sleeps are faked away.
        Sleep::fake();
        Date::setTestNow('2026-07-16 12:00:00');
        Show::factory()->create(['_tvdb_id' => 434847, 'episodes_synced_at' => now(), '_tvdb_defaultSeasonType' => 1]);
        fakeTvdbEpisodesWithFailingEpisodeFetch();

        // Act
        $this->artisan('catalog:sync-episodes-tvdb');

        // Assert
        expect(Cache::get(SyncFeed::TvdbEpisodes->cacheKey()))->toBeNull();
    });
});

describe('catalog:sync-episodes-tvdb feed record selection', function (): void {
    it('skips a show in the feed that has not yet been seeded', function (): void {
        // Arrange
        fakeTvdbEpisodes();
        Show::factory()->create(['_tvdb_id' => 469484, 'episodes_synced_at' => null, '_tvdb_defaultSeasonType' => 1]);

        // Act
        $this->artisan('catalog:sync-episodes-tvdb');

        // Assert
        // Named by episode id, not series id: the run now asks for episodes, so an
        // unseeded series shows up as its changed ids never being fetched.
        $this->assertDatabaseCount('episodes', 0);
        Http::assertNotSent(fn (Request $request): bool => Str::contains($request->url(), '/episodes/11846050'));
        Http::assertNotSent(fn (Request $request): bool => Str::contains($request->url(), '/episodes/11846051'));
    });

    it('exits SUCCESS', function (): void {
        // Arrange
        fakeTvdbEpisodes();

        // Act & Assert
        $this->artisan('catalog:sync-episodes-tvdb')->assertExitCode(0);
    });

    it('fetches each changed episode exactly once when its series repeats across feed records', function (): void {
        // Arrange
        fakeTvdbEpisodes();
        Show::factory()->create(['_tvdb_id' => 434847, 'episodes_synced_at' => now(), '_tvdb_defaultSeasonType' => 1]);

        // Act
        $this->artisan('catalog:sync-episodes-tvdb');

        // Assert
        // 434847 carries two records on feed page 0. A per-record fetch would ask
        // for each id as many times as its series appears; the drain dedupes first.
        expect(Http::recorded(fn (Request $request): bool => Str::contains($request->url(), '/episodes/9786562'))->count())->toBe(1)
            ->and(Http::recorded(fn (Request $request): bool => Str::contains($request->url(), '/episodes/9786563'))->count())->toBe(1);
    });

    it('fetches episodes whose series appears only on a later feed page', function (): void {
        // Arrange
        fakeTvdbEpisodes();
        Show::factory()->create(['_tvdb_id' => 371082, 'episodes_synced_at' => now(), '_tvdb_defaultSeasonType' => 1]);

        // Act
        $this->artisan('catalog:sync-episodes-tvdb');

        // Assert
        $this->assertDatabaseCount('episodes', 2);
        Http::assertSent(fn (Request $request): bool => Str::contains($request->url(), '/episodes/9256455'));
        Http::assertSent(fn (Request $request): bool => Str::contains($request->url(), '/episodes/9256456'));
    });

    it('skips a feed record whose recordId or seriesId is missing or non-numeric', function (): void {
        // Arrange
        // Synthetic feed body: records missing an id entirely, or carrying free text
        // where an id belongs, are malformed inputs a byte-exact real capture can't
        // provide. Records otherwise keep TheTVDB's real /updates shape. Both ids
        // are load-bearing now, so each is broken in turn while a well-formed
        // sibling of the same seeded series rides alongside.
        $body = json_encode(['status' => 'success', 'data' => [
            Arr::except(tvdbEpisodeUpdateRecord(9256455, 371082), 'seriesId'),
            tvdbEpisodeUpdateRecord(9256456, 'abc'),
            Arr::except(tvdbEpisodeUpdateRecord(0, 434847), 'recordId'),
            tvdbEpisodeUpdateRecord('abc', 434847),
            tvdbEpisodeUpdateRecord(9786562, 434847),
        ], 'links' => ['prev' => null, 'self' => '/updates', 'next' => null]]);
        Http::fake([
            '*api4.thetvdb.com/v4/login*' => Http::response(fixtureBytes('Catalog/tvdb/login.json')),
            '*api4.thetvdb.com/v4/updates*' => Http::response($body),
            '*api4.thetvdb.com/v4/episodes/9786562*' => Http::response(fixtureBytes('Catalog/tvdb/episode_9786562.json')),
            '*api4.thetvdb.com/v4/episodes/9256455*' => Http::response(fixtureBytes('Catalog/tvdb/episode_9256455.json')),
            '*api4.thetvdb.com/v4/episodes/9256456*' => Http::response(fixtureBytes('Catalog/tvdb/episode_9256456.json')),
        ]);
        Show::factory()->create(['_tvdb_id' => 434847, 'episodes_synced_at' => now(), '_tvdb_defaultSeasonType' => 1]);

        // Act
        $this->artisan('catalog:sync-episodes-tvdb')->assertExitCode(0);

        // Assert
        $this->assertDatabaseCount('episodes', 1);
        Http::assertSent(fn (Request $request): bool => Str::contains($request->url(), '/episodes/9786562'));
        Http::assertNotSent(fn (Request $request): bool => Str::contains($request->url(), '/episodes/9256455'));
        Http::assertNotSent(fn (Request $request): bool => Str::contains($request->url(), '/episodes/9256456'));
    });

    it('skips a feed record whose seriesId would truncate to a different real show', function (): void {
        // Arrange
        // Synthetic feed body: a decimal, an exponential, and a slug-appended
        // seriesId are malformed inputs a byte-exact real capture can't provide.
        // Each is numeric-ish enough to survive a bare is_numeric() guard and then
        // truncate under (int) to a plausible but wrong id — "70327.5" → 70327,
        // "1e5" → 100000, "1335814-slug" → 1335814 — so all three truncations are
        // seeded shows here, and crawling any of them is the defect. Records
        // otherwise keep TheTVDB's real /updates shape.
        $body = json_encode(['status' => 'success', 'data' => [
            tvdbEpisodeUpdateRecord(9786562, '70327.5'),
            tvdbEpisodeUpdateRecord(9786563, '1e5'),
            tvdbEpisodeUpdateRecord(9786564, '1335814-slug'),
            tvdbEpisodeUpdateRecord(9786565, 434847),
        ], 'links' => ['prev' => null, 'self' => '/updates', 'next' => null]]);
        // One /episodes capture replayed for every show would collide on the globally
        // unique episodes._tvdb_id, so each page's ids are offset by the series the walk
        // is currently on — otherwise a truncated id crawling a second show would abort
        // the run on a constraint violation instead of reaching the assertion below.
        $currentSeries = 0;
        Http::fake([
            '*api4.thetvdb.com/v4/login*' => Http::response(fixtureBytes('Catalog/tvdb/login.json')),
            '*api4.thetvdb.com/v4/updates*' => Http::response($body),
            '*api4.thetvdb.com/v4/series/*/episodes*' => function (Request $request) use (&$currentSeries) {
                $isFollowUp = Str::contains($request->url(), 'page=1');

                if (! $isFollowUp) {
                    $currentSeries = (int) Str::before(Str::after($request->url(), '/series/'), '/');
                }

                $payload = json_decode(fixtureBytes($isFollowUp
                    ? 'Catalog/tvdb/series_episodes_page2.json'
                    : 'Catalog/tvdb/series_episodes_page1.json'), true);
                $payload['data']['episodes'] = array_map(
                    fn (array $episode): array => [...$episode, 'id' => $episode['id'] + $currentSeries, 'seriesId' => $currentSeries],
                    $payload['data']['episodes'],
                );

                return Http::response(json_encode($payload));
            },
        ]);
        collect([70327, 100000, 1335814, 434847])->each(fn (int $tvdbId) => Show::factory()->create([
            '_tvdb_id' => $tvdbId,
            'episodes_synced_at' => now(),
            '_tvdb_defaultSeasonType' => 1,
        ]));

        // Act
        $this->artisan('catalog:sync-episodes-tvdb');

        // Assert
        Http::assertSent(fn (Request $request): bool => Str::contains($request->url(), '/series/434847/episodes'));
        // page=1 follow-ups are excluded: the /episodes page-2 fixture is a real
        // capture whose links.next names its own (different) series id.
        Http::assertNotSent(fn (Request $request): bool => Str::contains($request->url(), '/series/')
            && ! Str::contains($request->url(), 'page=1')
            && ! Str::contains($request->url(), '/series/434847/'));
    });
});

describe('catalog:sync-episodes-tvdb show lookup', function (): void {
    it('looks up feed series in chunks of 1000 ids', function (): void {
        // Arrange
        // Synthetic feed body: a >1000-record page is a structural input a committed
        // real fixture can't practically provide. No shows are seeded, so the run
        // issues no /episodes calls and the only `shows` reads are the membership
        // lookups under test.
        $records = array_map(
            fn (int $seriesId): array => tvdbEpisodeUpdateRecord(9786562 + $seriesId, $seriesId),
            range(1, 1001),
        );
        $body = json_encode(['status' => 'success', 'data' => $records, 'links' => ['prev' => null, 'self' => '/updates', 'next' => null]]);
        Http::fake([
            '*api4.thetvdb.com/v4/login*' => Http::response(fixtureBytes('Catalog/tvdb/login.json')),
            '*api4.thetvdb.com/v4/updates*' => Http::response($body),
        ]);
        DB::enableQueryLog();

        // Act
        $this->artisan('catalog:sync-episodes-tvdb');

        // Assert
        $selects = loggedShowSelects();
        expect($selects)->toHaveCount(2)
            ->and(count($selects[0]['bindings']))->toBe(1000)
            ->and(count($selects[1]['bindings']))->toBe(1);
    });

    it('reads only the columns the episode refresh needs from a matched show', function (): void {
        // Arrange
        fakeTvdbEpisodes();
        Show::factory()->create(['_tvdb_id' => 434847, 'episodes_synced_at' => now(), '_tvdb_defaultSeasonType' => 1]);
        DB::enableQueryLog();

        // Act
        $this->artisan('catalog:sync-episodes-tvdb');

        // Assert
        // RefreshTvdbEpisodes reads `_tvdb_id` (to attribute each payload to a show)
        // and `_tvdb_defaultSeasonType` (season resolution) off each matched show; a
        // wildcard select drags every other column of the row through memory.
        expect(loggedShowSelects()[0]['query'])
            ->toContain('_tvdb_defaultSeasonType')
            ->toContain('_tvdb_id')
            ->not->toContain('select *');
    });

    it('stamps episodes_synced_at on every show whose episodes it refreshed', function (): void {
        // Arrange
        // Each touched show is re-stamped as the refresh persists its episodes, so a
        // skipped show surfaces here. The stamps start a day behind run-start so the
        // re-stamp is observable.
        Date::setTestNow('2026-07-16 12:00:00');
        fakeTvdbEpisodes();
        collect([434847, 469484, 371082])->each(fn (int $tvdbId) => Show::factory()->create([
            '_tvdb_id' => $tvdbId,
            'episodes_synced_at' => now()->subDay(),
            '_tvdb_defaultSeasonType' => 1,
        ]));

        // Act
        $this->artisan('catalog:sync-episodes-tvdb');

        // Assert
        expect(Show::query()->pluck('episodes_synced_at')->map->toDateTimeString()->all())
            ->toBe(array_fill(0, 3, now()->toDateTimeString()));
        $this->assertDatabaseCount('episodes', 6);
    });
});

describe('catalog:sync-episodes-tvdb progress output', function (): void {
    it('announces the feed drain before reading the update feed', function (): void {
        // Arrange
        fakeTvdbEpisodes();

        // Act & Assert
        $this->artisan('catalog:sync-episodes-tvdb')->expectsOutputToContain('Reading the episodes update feed…');
    });

    it('announces the episode refresh before syncing episodes', function (): void {
        // Arrange
        fakeTvdbEpisodes();

        // Act & Assert
        $this->artisan('catalog:sync-episodes-tvdb')->expectsOutputToContain('Syncing episodes…');
    });

    it('beats the feed drain once every 10 000 records', function (): void {
        // Arrange
        // Synthetic feed body: a >10 000-record page is a structural input a committed
        // real fixture can't practically provide (the committed capture carries 4).
        // Records otherwise keep TheTVDB's real /updates shape, varying only the ids.
        // No shows are seeded, so the run issues no /episodes calls and the drain is
        // the only work it does.
        $records = array_map(
            fn (int $seriesId): array => tvdbEpisodeUpdateRecord(9786562 + $seriesId, $seriesId),
            range(1, 10001),
        );
        $body = json_encode(['status' => 'success', 'data' => $records, 'links' => ['prev' => null, 'self' => '/updates', 'next' => null]]);
        Http::fake([
            '*api4.thetvdb.com/v4/login*' => Http::response(fixtureBytes('Catalog/tvdb/login.json')),
            '*api4.thetvdb.com/v4/updates*' => Http::response($body),
        ]);

        // 10 001 records cross exactly one 10 000 boundary, so the first boundary being
        // present while the second is absent pins the cadence at once per 10 000 —
        // a per-record or per-page beat would fail one of the two.
        // Act & Assert
        $this->artisan('catalog:sync-episodes-tvdb')
            ->expectsOutputToContain('  [tvdb feed 10000]')
            ->doesntExpectOutputToContain('  [tvdb feed 20000]');
    });

    it('emits a source-prefixed episode-count heartbeat once the running total crosses 100', function (): void {
        // Arrange
        // Synthetic feed body: 102 changed episodes of one series (the committed
        // capture carries 6 across three) is a structural input a real fixture can't
        // practically provide, and is the smallest set that crosses a 100 beat.
        $recordIds = array_map(fn (int $offset): int => 9786562 + $offset, range(0, 101));
        $body = json_encode(['status' => 'success', 'data' => array_map(
            fn (int $recordId): array => tvdbEpisodeUpdateRecord($recordId, 434847),
            $recordIds,
        ), 'links' => ['prev' => null, 'self' => '/updates', 'next' => null]]);
        // One /episodes capture replayed for every id would collide on the globally
        // unique episodes._tvdb_id, so each response's `id` is taken from the id the
        // request asked for, and its `seriesId` pinned to the one seeded show — the
        // real record's wire shape, varying only the ids.
        Http::fake([
            '*api4.thetvdb.com/v4/login*' => Http::response(fixtureBytes('Catalog/tvdb/login.json')),
            '*api4.thetvdb.com/v4/updates*' => Http::response($body),
            '*api4.thetvdb.com/v4/episodes/*' => function (Request $request) {
                $payload = json_decode(fixtureBytes('Catalog/tvdb/episode_9786562.json'), true);
                $payload['data'] = [
                    ...$payload['data'],
                    'id' => (int) Str::afterLast($request->url(), '/'),
                    'seriesId' => 434847,
                ];

                return Http::response(json_encode($payload));
            },
        ]);
        Show::factory()->create(['_tvdb_id' => 434847, 'episodes_synced_at' => now(), '_tvdb_defaultSeasonType' => 1]);

        // The batch takes the total to 102, the first crossing of a 100 boundary; a
        // run of 6 episodes must stay silent, or the beat is per batch, not per 100.
        // The half-rename guard has to be `[episodes ` — bracket AND trailing space —
        // because the prefixed line `[tvdb episodes 102]` itself contains the substring
        // `episodes 102]`, so a naked `episodes` guard would reject the very line it
        // exists to allow. Only the opening bracket immediately followed by the bare tag
        // identifies the old, unprefixed shape.
        // Act & Assert
        $this->artisan('catalog:sync-episodes-tvdb')
            ->expectsOutputToContain('[tvdb episodes 102]')
            ->doesntExpectOutputToContain('[tvdb episodes 6]')
            ->doesntExpectOutputToContain('[episodes ');
    });
});

describe('catalog:sync-episodes-tvdb run-closing output', function (): void {
    it('reports its exact final count on a run that never reaches the beat interval', function (): void {
        // Arrange
        // The happy-path fake refreshes the two changed episodes of one seeded show —
        // far short of the 100-episode beat interval, which is why nothing is printed
        // before the flush. The count is pinned to the observed run (the sibling
        // `Synced 2 episodes` line), not to the interval arithmetic.
        fakeTvdbEpisodes();
        Show::factory()->create(['_tvdb_id' => 434847, 'episodes_synced_at' => now(), '_tvdb_defaultSeasonType' => 1]);

        // Act & Assert
        $this->artisan('catalog:sync-episodes-tvdb')->expectsOutputToContain('  [tvdb episodes 2]');
    });

    it('ends the run with a Done. line', function (): void {
        // Arrange
        fakeTvdbEpisodes();
        Show::factory()->create(['_tvdb_id' => 434847, 'episodes_synced_at' => now(), '_tvdb_defaultSeasonType' => 1]);

        // Act & Assert
        $this->artisan('catalog:sync-episodes-tvdb')->expectsOutputToContain('Done.');
    });
});

describe('catalog:sync-episodes-tvdb index silence and elapsed phase lines', function (): void {
    /*
    |--------------------------------------------------------------------------
    | Index silence & elapsed phase lines
    |--------------------------------------------------------------------------
    | The leg writes no searchable content — the refresh ends on an
    | `episodes_synced_at` stamp, whose model save the `Searchable` trait syncs to
    | the engine inline, once per show touched. That bookkeeping traffic is what the
    | leg must suppress, and there is no reindex phase to pair it with: nothing the
    | engine cares about changed. The tests below freeze the clock, which pins both
    | phases' elapsed readings at `0s`.
    */
    it('sends nothing to the search engine while syncing episodes', function (): void {
        // Arrange
        fakeTvdbEpisodes();
        Show::factory()->create(['_tvdb_id' => 434847, 'episodes_synced_at' => now(), '_tvdb_defaultSeasonType' => 1]);
        $capturedChunks = spyOnScoutEngine();

        // Act
        $this->artisan('catalog:sync-episodes-tvdb')->run();

        // Assert
        expect($capturedChunks())->toBe([]);
    });

    it('prints the feed-drain completion line with elapsed time', function (): void {
        // Arrange
        Date::setTestNow('2026-07-16 12:00:00');
        fakeTvdbEpisodes();

        // Act & Assert
        $this->artisan('catalog:sync-episodes-tvdb')->expectsOutputToContain('Read the episodes update feed in 0s');
    });

    it('prints the synced-episodes completion line with the run\'s episode count and elapsed time', function (): void {
        // Arrange
        Date::setTestNow('2026-07-16 12:00:00');
        fakeTvdbEpisodes();
        Show::factory()->create(['_tvdb_id' => 434847, 'episodes_synced_at' => now(), '_tvdb_defaultSeasonType' => 1]);

        // Act & Assert
        $this->artisan('catalog:sync-episodes-tvdb')->expectsOutputToContain('Synced 2 episodes in 0s');
    });

    it('counts every seeded show\'s changed episodes in the synced-episodes line', function (): void {
        // Arrange
        Date::setTestNow('2026-07-16 12:00:00');
        fakeTvdbEpisodes();
        collect([434847, 469484, 371082])->each(fn (int $tvdbId) => Show::factory()->create([
            '_tvdb_id' => $tvdbId,
            'episodes_synced_at' => now(),
            '_tvdb_defaultSeasonType' => 1,
        ]));

        // Act & Assert
        $this->artisan('catalog:sync-episodes-tvdb')->expectsOutputToContain('Synced 6 episodes in 0s');
    });

    it('a window matching no seeded shows still prints both completion lines and exits 0', function (): void {
        // Arrange
        // A quiet window, not a failed one: the feed's shows are all unseeded, so no
        // episode is fetched, yet both phases still report and the run exits clean.
        Date::setTestNow('2026-07-16 12:00:00');
        fakeTvdbEpisodes();

        // Act & Assert
        $this->artisan('catalog:sync-episodes-tvdb')
            ->expectsOutputToContain('Read the episodes update feed in 0s')
            ->expectsOutputToContain('Synced 0 episodes in 0s')
            ->assertExitCode(0);
    });
});

describe('catalog:sync-episodes-tvdb failed-episode run outcome', function (): void {
    it('exits FAILURE when a changed episode\'s fetch failed', function (): void {
        // Arrange
        Sleep::fake();
        Date::setTestNow('2026-07-16 12:00:00');
        Show::factory()->create(['_tvdb_id' => 434847, 'episodes_synced_at' => now(), '_tvdb_defaultSeasonType' => 1]);
        fakeTvdbEpisodesWithFailingEpisodeFetch();

        // Act & Assert
        $this->artisan('catalog:sync-episodes-tvdb')->assertExitCode(Command::FAILURE);
    });

    it('closes the run with the failed episode count and the marker consequence', function (): void {
        // Arrange
        // One seeded show contributes two changed episode ids and both 500, so the
        // run's failure count is 2 — a failure is now one unfetchable episode, not
        // one show and not one HTTP attempt.
        Sleep::fake();
        Date::setTestNow('2026-07-16 12:00:00');
        Show::factory()->create(['_tvdb_id' => 434847, 'episodes_synced_at' => now(), '_tvdb_defaultSeasonType' => 1]);
        fakeTvdbEpisodesWithFailingEpisodeFetch();

        // Act
        $this->artisan('catalog:sync-episodes-tvdb')
            ->expectsOutputToContain('2 episodes failed; marker not advanced.')
            ->doesntExpectOutputToContain('  2 episodes failed')
            ->run();

        // Assert
        // The consequence the line claims, proven alongside the line itself.
        expect(Cache::get(SyncFeed::TvdbEpisodes->cacheKey()))->toBeNull();
    });

    it('treats a 404 episode as a miss rather than a failure', function (): void {
        // Arrange
        // TheTVDB 404s an episode it deleted since the feed named it — a settled
        // upstream answer, so the window IS fully covered and the marker may move.
        Date::setTestNow('2026-07-16 12:00:00');
        Show::factory()->create(['_tvdb_id' => 434847, 'episodes_synced_at' => now(), '_tvdb_defaultSeasonType' => 1]);
        Http::fake([
            '*api4.thetvdb.com/v4/login*' => Http::response(fixtureBytes('Catalog/tvdb/login.json')),
            '*api4.thetvdb.com/v4/updates*' => fn (Request $request) => Str::contains($request->url(), 'page=1')
                ? Http::response(fixtureBytes('Catalog/tvdb/episode_updates_page2.json'))
                : Http::response(fixtureBytes('Catalog/tvdb/episode_updates.json')),
            '*api4.thetvdb.com/v4/episodes/9786562*' => Http::response('', 404),
            '*api4.thetvdb.com/v4/episodes/9786563*' => Http::response(fixtureBytes('Catalog/tvdb/episode_9786563.json')),
        ]);

        // Act
        $this->artisan('catalog:sync-episodes-tvdb')->assertExitCode(0)->run();

        // Assert
        expect(Cache::get(SyncFeed::TvdbEpisodes->cacheKey()))->toBe(now()->toIso8601String());
        $this->assertDatabaseCount('episodes', 1);
    });
});

describe('catalog:sync-episodes-tvdb feed page failure', function (): void {
    it('aborts the run and leaves the marker untouched when a feed page fails', function (): void {
        // Arrange
        Http::fake([
            '*api4.thetvdb.com/v4/login*' => Http::response(fixtureBytes('Catalog/tvdb/login.json')),
            '*api4.thetvdb.com/v4/updates*' => Http::response('', 500),
        ]);

        // Act & Assert
        // The feed is drained lazily, so its failure surfaces mid-drain — it must
        // still escape handle() rather than being swallowed by the drain loop.
        expect(fn () => $this->artisan('catalog:sync-episodes-tvdb')->run())->toThrow(TvdbRequestFailed::class);
        expect(Cache::get(SyncFeed::TvdbEpisodes->cacheKey()))->toBeNull();
    });
});
