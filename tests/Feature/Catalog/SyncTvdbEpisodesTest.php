<?php

declare(strict_types=1);

use App\Domains\Catalog\Enums\SyncFeed;
use App\Domains\Catalog\Models\Show;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;
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

it('does not advance the marker on a --limit run', function (): void {
    // Arrange
    Date::setTestNow('2026-07-16 12:00:00');
    fakeTvdbEpisodes();
    Show::factory()->create(['_tvdb_id' => 434847, 'episodes_synced_at' => now(), '_tvdb_defaultSeasonType' => 1]);

    // Act
    $this->artisan('catalog:sync-episodes-tvdb', ['--limit' => 1]);

    // Assert
    expect(Cache::get(SyncFeed::TvdbEpisodes->cacheKey()))->toBeNull();
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

it('caps shows processed with --limit', function (): void {
    // Arrange
    fakeTvdbEpisodes();
    Show::factory()->create(['_tvdb_id' => 434847, 'episodes_synced_at' => now(), '_tvdb_defaultSeasonType' => 1]);
    Show::factory()->create(['_tvdb_id' => 371082, 'episodes_synced_at' => now(), '_tvdb_defaultSeasonType' => 1]);

    // Act
    $this->artisan('catalog:sync-episodes-tvdb', ['--limit' => 1]);

    // Assert
    // Each processed show fires one initial /episodes fetch (page 2 carries
    // page=1), so the count of non-paged episode requests is the shows processed.
    $initialEpisodeFetches = 0;
    Http::assertSent(function (Request $request) use (&$initialEpisodeFetches): bool {
        if (Str::contains($request->url(), '/episodes') && ! Str::contains($request->url(), 'page=1')) {
            $initialEpisodeFetches++;
        }

        return true;
    });
    expect($initialEpisodeFetches)->toBe(1);
});

it('processes the lowest-id show first under --limit so a capped run is deterministic', function (): void {
    // Arrange
    fakeTvdbEpisodes();
    $first = Show::factory()->create(['_tvdb_id' => 434847, 'episodes_synced_at' => now(), '_tvdb_defaultSeasonType' => 1]);
    Show::factory()->create(['_tvdb_id' => 371082, 'episodes_synced_at' => now(), '_tvdb_defaultSeasonType' => 1]);

    // Act
    $this->artisan('catalog:sync-episodes-tvdb', ['--limit' => 1]);

    // Assert
    Http::assertSent(fn (Request $request): bool => Str::contains($request->url(), '/series/'.$first->_tvdb_id.'/episodes'));
    Http::assertNotSent(fn (Request $request): bool => Str::contains($request->url(), '/series/371082/episodes'));
});

it('exits SUCCESS', function (): void {
    // Arrange
    fakeTvdbEpisodes();

    // Act & Assert
    $this->artisan('catalog:sync-episodes-tvdb')->assertExitCode(0);
});
