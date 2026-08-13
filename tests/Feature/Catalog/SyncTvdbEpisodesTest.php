<?php

declare(strict_types=1);

use App\Domains\Catalog\Enums\SyncFeed;
use App\Domains\Catalog\Exceptions\TvdbRequestFailed;
use App\Domains\Catalog\Models\Show;
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

beforeEach(function (): void {
    Cache::flush();
    config(['services.tvdb.key' => 'test-key']);
});

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
    Cache::forever(SyncFeed::TvdbEpisodes->cacheKey(), $marker);
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
    expect(Cache::get(SyncFeed::TvdbEpisodes->cacheKey())->equalTo(now()))->toBeTrue();
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

it('emits an episode-count heartbeat once the running total crosses 100', function (): void {
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
    // Act & Assert
    $this->artisan('catalog:sync-episodes-tvdb')
        ->expectsOutputToContain('[episodes 102]')
        ->doesntExpectOutputToContain('[episodes 6]');
});

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
