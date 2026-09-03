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
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Fixtures (byte-exact real TheTVDB v4 slices)
|--------------------------------------------------------------------------
| catalog:sync-episodes-tvdb pulls the /updates?type=episodes feed since the
| TvdbEpisodes marker (6h overlap, 24h no-marker fallback, 14d cap), reduces it
| to distinct seriesIds, keeps only shows already seeded (episodes_synced_at not
| null), and re-runs SeedTvdbEpisodes per show.
|
| tests/Fixtures/Catalog/tvdb/login.json — POST /login → data.token JWT;
|   every fake map answers it because Http::preventStrayRequests() is global.
| tests/Fixtures/Catalog/tvdb/episode_updates.json + episode_updates_page2.json —
|   the /updates?type=episodes feed, chained p0 → p1 → null via links.next.
|   Each record carries `seriesId`: 434847 ×2, 469484 ×2 on page 0; 371082 ×2 on
|   page 1. Distinct seriesIds across the walk: 434847, 469484, 371082.
| tests/Fixtures/Catalog/tvdb/series_episodes_page1.json + series_episodes_page2.json —
|   a series' /episodes/default listing, chained via links.next; 3 + 3 = 6
|   episodes total per walked show.
|
| Both the /updates and /episodes walks page via `links.next` ending in
| `&page=1`, so the two fakes are keyed on distinct URL segments (/updates vs
| /series/.../episodes) and each branches page=1 → its own page 2.
*/

function fakeTvdbEpisodes(): void
{
    Http::fake([
        '*api4.thetvdb.com/v4/login*' => Http::response(fixtureBytes('Catalog/tvdb/login.json')),
        '*api4.thetvdb.com/v4/updates*' => fn (Request $request) => Str::contains($request->url(), 'page=1')
            ? Http::response(fixtureBytes('Catalog/tvdb/episode_updates_page2.json'))
            : Http::response(fixtureBytes('Catalog/tvdb/episode_updates.json')),
        '*api4.thetvdb.com/v4/series/*/episodes*' => fn (Request $request) => Str::contains($request->url(), 'page=1')
            ? Http::response(fixtureBytes('Catalog/tvdb/series_episodes_page2.json'))
            : Http::response(fixtureBytes('Catalog/tvdb/series_episodes_page1.json')),
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
 * One /updates record in TheTVDB's real wire shape, varying only the ids.
 *
 * @return array<string, mixed>
 */
function tvdbEpisodeUpdateRecord(int $recordId, mixed $seriesId): array
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
 * The happy-path fakes with the per-show /episodes fetch 500ing: the feed itself
 * still drains cleanly, so every seeded show the walk reaches fails on its own
 * fetch and the run ends with failures to report.
 */
function fakeTvdbEpisodesWithFailingShowFetch(): void
{
    Http::fake([
        '*api4.thetvdb.com/v4/login*' => Http::response(fixtureBytes('Catalog/tvdb/login.json')),
        '*api4.thetvdb.com/v4/updates*' => fn (Request $request) => Str::contains($request->url(), 'page=1')
            ? Http::response(fixtureBytes('Catalog/tvdb/episode_updates_page2.json'))
            : Http::response(fixtureBytes('Catalog/tvdb/episode_updates.json')),
        '*api4.thetvdb.com/v4/series/*/episodes*' => Http::response('', 500),
    ]);
}

beforeEach(function (): void {
    Cache::flush();
    config(['services.tvdb.key' => 'test-key']);
});

describe('catalog:sync-episodes-tvdb feed hydration and marker window', function (): void {
    it('hydrates a seeded show that appears in the episodes feed', function (): void {
        // Arrange
        fakeTvdbEpisodes();
        Show::factory()->create(['_tvdb_id' => 434847, 'episodes_synced_at' => now(), '_tvdb_defaultSeasonType' => 1]);

        // Act
        $this->artisan('catalog:sync-episodes-tvdb');

        // Assert
        $this->assertDatabaseCount('episodes', 6);
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

    it('does not advance the marker when an episodes fetch fails', function (): void {
        // Arrange
        Date::setTestNow('2026-07-16 12:00:00');
        Show::factory()->create(['_tvdb_id' => 434847, 'episodes_synced_at' => now(), '_tvdb_defaultSeasonType' => 1]);
        Http::fake([
            '*api4.thetvdb.com/v4/login*' => Http::response(fixtureBytes('Catalog/tvdb/login.json')),
            '*api4.thetvdb.com/v4/updates*' => fn (Request $request) => Str::contains($request->url(), 'page=1')
                ? Http::response(fixtureBytes('Catalog/tvdb/episode_updates_page2.json'))
                : Http::response(fixtureBytes('Catalog/tvdb/episode_updates.json')),
            '*api4.thetvdb.com/v4/series/*/episodes*' => Http::response('', 500),
        ]);

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
        $this->assertDatabaseCount('episodes', 0);
        Http::assertNotSent(fn (Request $request): bool => Str::contains($request->url(), '/series/469484/episodes'));
    });

    it('exits SUCCESS', function (): void {
        // Arrange
        fakeTvdbEpisodes();

        // Act & Assert
        $this->artisan('catalog:sync-episodes-tvdb')->assertExitCode(0);
    });

    it('processes a show once when its seriesId repeats in the feed', function (): void {
        // Arrange
        fakeTvdbEpisodes();
        Show::factory()->create(['_tvdb_id' => 434847, 'episodes_synced_at' => now(), '_tvdb_defaultSeasonType' => 1]);

        // Act
        $this->artisan('catalog:sync-episodes-tvdb');

        // Assert
        // 434847 carries two records on feed page 0; the /episodes walk itself pages
        // via page=1, so those follow-ups are excluded to count processings, not calls.
        expect(Http::recorded(fn (Request $request): bool => Str::contains($request->url(), '/series/434847/episodes')
            && ! Str::contains($request->url(), 'page=1'))->count())->toBe(1);
    });

    it('processes a show whose seriesId appears only on a later feed page', function (): void {
        // Arrange
        fakeTvdbEpisodes();
        Show::factory()->create(['_tvdb_id' => 371082, 'episodes_synced_at' => now(), '_tvdb_defaultSeasonType' => 1]);

        // Act
        $this->artisan('catalog:sync-episodes-tvdb');

        // Assert
        Http::assertSent(fn (Request $request): bool => Str::contains($request->url(), '/series/371082/episodes'));
    });

    it('skips feed records with a missing or non-numeric seriesId', function (): void {
        // Arrange
        // Synthetic feed body: a record missing `seriesId` entirely, and one carrying
        // free text, are malformed inputs a byte-exact real capture can't provide.
        // Records otherwise keep TheTVDB's real /updates shape.
        $body = json_encode(['status' => 'success', 'data' => [
            Arr::except(tvdbEpisodeUpdateRecord(9786562, 0), 'seriesId'),
            tvdbEpisodeUpdateRecord(9786563, 'abc'),
            tvdbEpisodeUpdateRecord(9786564, 434847),
        ], 'links' => ['prev' => null, 'self' => '/updates', 'next' => null]]);
        Http::fake([
            '*api4.thetvdb.com/v4/login*' => Http::response(fixtureBytes('Catalog/tvdb/login.json')),
            '*api4.thetvdb.com/v4/updates*' => Http::response($body),
            '*api4.thetvdb.com/v4/series/*/episodes*' => fn (Request $request) => Str::contains($request->url(), 'page=1')
                ? Http::response(fixtureBytes('Catalog/tvdb/series_episodes_page2.json'))
                : Http::response(fixtureBytes('Catalog/tvdb/series_episodes_page1.json')),
        ]);
        Show::factory()->create(['_tvdb_id' => 434847, 'episodes_synced_at' => now(), '_tvdb_defaultSeasonType' => 1]);

        // Act
        $this->artisan('catalog:sync-episodes-tvdb')->assertExitCode(0);

        // Assert
        Http::assertSent(fn (Request $request): bool => Str::contains($request->url(), '/series/434847/episodes'));
        // page=1 follow-ups are excluded: the /episodes page-2 fixture is a real
        // capture whose links.next names its own (different) series id.
        Http::assertNotSent(fn (Request $request): bool => Str::contains($request->url(), '/series/')
            && ! Str::contains($request->url(), 'page=1')
            && ! Str::contains($request->url(), '/series/434847/'));
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

describe('catalog:sync-episodes-tvdb show lookup and walk', function (): void {
    it('looks up feed shows in chunks of 1000 ids', function (): void {
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

    it('walks the matched shows in primary-key pages of 200', function (): void {
        // Arrange
        fakeTvdbEpisodes();
        Show::factory()->create(['_tvdb_id' => 434847, 'episodes_synced_at' => now(), '_tvdb_defaultSeasonType' => 1]);
        DB::enableQueryLog();

        // Act
        $this->artisan('catalog:sync-episodes-tvdb');

        // Assert
        // Pagination shape only, on unquoted substrings: identifier quoting differs
        // between the sqlite test DB and MySQL, while the compiled `limit` inlines
        // its integer identically in both dialects.
        expect(loggedShowSelects()[0]['query'])
            ->toContain('order by')
            ->toContain('limit 200');
    });

    it('reads only the columns the seeding action needs from a matched show', function (): void {
        // Arrange
        fakeTvdbEpisodes();
        Show::factory()->create(['_tvdb_id' => 434847, 'episodes_synced_at' => now(), '_tvdb_defaultSeasonType' => 1]);
        DB::enableQueryLog();

        // Act
        $this->artisan('catalog:sync-episodes-tvdb');

        // Assert
        // SeedTvdbEpisodes reads `_tvdb_id` (the /episodes fetch) and
        // `_tvdb_defaultSeasonType` (season resolution) off each walked show; a
        // wildcard select drags every other column of the row through memory.
        expect(loggedShowSelects()[0]['query'])
            ->toContain('_tvdb_defaultSeasonType')
            ->toContain('_tvdb_id')
            ->not->toContain('select *');
    });

    it('processes every matched show exactly once while stamping the rows it walks', function (): void {
        // Arrange
        // Each show is stamped mid-walk (SeedTvdbEpisodes ends on an
        // episodes_synced_at update), so a skipped or re-processed row surfaces here.
        // The stamps start a day behind run-start so the re-stamp is observable.
        Date::setTestNow('2026-07-16 12:00:00');
        // One /episodes capture replayed for every show would collide on the globally
        // unique episodes._tvdb_id, so each page's ids are offset by the series the
        // walk is currently on — the real records' wire shape, varying only the ids.
        // The offset is tracked across pages because the page-2 fixture's links.next
        // is followed under the capture's own (different) series id.
        $currentSeries = 0;
        Http::fake([
            '*api4.thetvdb.com/v4/login*' => Http::response(fixtureBytes('Catalog/tvdb/login.json')),
            '*api4.thetvdb.com/v4/updates*' => fn (Request $request) => Str::contains($request->url(), 'page=1')
                ? Http::response(fixtureBytes('Catalog/tvdb/episode_updates_page2.json'))
                : Http::response(fixtureBytes('Catalog/tvdb/episode_updates.json')),
            '*api4.thetvdb.com/v4/series/*/episodes*' => function (Request $request) use (&$currentSeries) {
                $isFollowUp = Str::contains($request->url(), 'page=1');

                if (! $isFollowUp) {
                    $currentSeries = (int) Str::before(Str::after($request->url(), '/series/'), '/');
                }

                $body = json_decode(fixtureBytes($isFollowUp
                    ? 'Catalog/tvdb/series_episodes_page2.json'
                    : 'Catalog/tvdb/series_episodes_page1.json'), true);
                $body['data']['episodes'] = array_map(
                    fn (array $episode): array => [...$episode, 'id' => $episode['id'] + $currentSeries, 'seriesId' => $currentSeries],
                    $body['data']['episodes'],
                );

                return Http::response(json_encode($body));
            },
        ]);
        collect([434847, 469484, 371082])->each(fn (int $tvdbId) => Show::factory()->create([
            '_tvdb_id' => $tvdbId,
            'episodes_synced_at' => now()->subDay(),
            '_tvdb_defaultSeasonType' => 1,
        ]));

        // Act
        $this->artisan('catalog:sync-episodes-tvdb');

        // Assert
        // page=1 follow-ups are excluded to count processings, not calls.
        expect(Http::recorded(fn (Request $request): bool => Str::contains($request->url(), '/episodes')
            && ! Str::contains($request->url(), 'page=1'))->count())->toBe(3)
            ->and(Show::query()->pluck('episodes_synced_at')->map->toDateTimeString()->all())
            ->toBe(array_fill(0, 3, now()->toDateTimeString()));
        $this->assertDatabaseCount('episodes', 18);
    });
});

describe('catalog:sync-episodes-tvdb progress output', function (): void {
    it('announces the feed drain before reading the update feed', function (): void {
        // Arrange
        fakeTvdbEpisodes();

        // Act & Assert
        $this->artisan('catalog:sync-episodes-tvdb')->expectsOutputToContain('Reading the episodes update feed…');
    });

    it('announces the show walk before seeding episodes', function (): void {
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
        // Synthetic feed body: 17 distinct seriesIds (the committed capture carries 3)
        // is a structural input a real fixture can't practically provide — 17 shows ×
        // 6 episodes is the smallest set that crosses a 100-episode beat.
        $seriesIds = array_map(fn (int $offset): int => $offset * 1000, range(1, 17));
        $body = json_encode(['status' => 'success', 'data' => array_map(
            fn (int $seriesId): array => tvdbEpisodeUpdateRecord(9786562 + $seriesId, $seriesId),
            $seriesIds,
        ), 'links' => ['prev' => null, 'self' => '/updates', 'next' => null]]);
        // One /episodes capture replayed for every show would collide on the globally
        // unique episodes._tvdb_id, so each page's ids are offset by the series the walk
        // is currently on. The offset is tracked across pages because the page-2
        // fixture's links.next is followed under the capture's own (different) series id.
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
        collect($seriesIds)->each(fn (int $tvdbId) => Show::factory()->create([
            '_tvdb_id' => $tvdbId,
            'episodes_synced_at' => now(),
            '_tvdb_defaultSeasonType' => 1,
        ]));

        // The 17th show takes the total to 102, the first crossing of a 100 boundary;
        // the shows before it must stay silent, or the beat is per show, not per 100.
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
        // The happy-path fake walks exactly one seeded show, whose /episodes crawl pages
        // 3 + 3 = 6 episodes — far short of the 100-episode beat interval, which is why
        // nothing is printed today. The count is pinned to the observed run (the sibling
        // `Synced 6 episodes` line), not to the interval arithmetic.
        fakeTvdbEpisodes();
        Show::factory()->create(['_tvdb_id' => 434847, 'episodes_synced_at' => now(), '_tvdb_defaultSeasonType' => 1]);

        // Act & Assert
        $this->artisan('catalog:sync-episodes-tvdb')->expectsOutputToContain('  [tvdb episodes 6]');
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
    | The leg writes no searchable content — SeedTvdbEpisodes ends on an
    | `episodes_synced_at` stamp, whose model save the `Searchable` trait syncs to
    | the engine inline, once per show walked. That bookkeeping traffic is what the
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
        $this->artisan('catalog:sync-episodes-tvdb')->expectsOutputToContain('Synced 6 episodes in 0s');
    });

    it('a window matching no seeded shows still prints both completion lines and exits 0', function (): void {
        // Arrange
        // A quiet window, not a failed one: the feed's shows are all unseeded, so no
        // show is walked, yet both phases still report and the run exits clean.
        Date::setTestNow('2026-07-16 12:00:00');
        fakeTvdbEpisodes();

        // Act & Assert
        $this->artisan('catalog:sync-episodes-tvdb')
            ->expectsOutputToContain('Read the episodes update feed in 0s')
            ->expectsOutputToContain('Synced 0 episodes in 0s')
            ->assertExitCode(0);
    });
});

describe('catalog:sync-episodes-tvdb failed-show run outcome', function (): void {
    it('exits FAILURE when a show\'s episodes fetch failed', function (): void {
        // Arrange
        Date::setTestNow('2026-07-16 12:00:00');
        Show::factory()->create(['_tvdb_id' => 434847, 'episodes_synced_at' => now(), '_tvdb_defaultSeasonType' => 1]);
        fakeTvdbEpisodesWithFailingShowFetch();

        // Act & Assert
        $this->artisan('catalog:sync-episodes-tvdb')->assertExitCode(Command::FAILURE);
    });

    it('closes the run with the failed show count and the marker consequence', function (): void {
        // Arrange
        // One seeded show is walked and its /episodes fetch 500s, so the run's failure
        // count is 1 — the catch is per show, not per episode or per HTTP attempt.
        Date::setTestNow('2026-07-16 12:00:00');
        Show::factory()->create(['_tvdb_id' => 434847, 'episodes_synced_at' => now(), '_tvdb_defaultSeasonType' => 1]);
        fakeTvdbEpisodesWithFailingShowFetch();

        // Act
        $this->artisan('catalog:sync-episodes-tvdb')
            ->expectsOutputToContain('1 shows failed; marker not advanced.')
            ->doesntExpectOutputToContain('  1 shows failed')
            ->run();

        // Assert
        // The consequence the line claims, proven alongside the line itself.
        expect(Cache::get(SyncFeed::TvdbEpisodes->cacheKey()))->toBeNull();
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
