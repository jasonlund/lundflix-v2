<?php

declare(strict_types=1);

use App\Domains\Catalog\Models\Show;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Fixtures (byte-exact real TheTVDB v4 slices)
|--------------------------------------------------------------------------
| tvdb:sync-shows is updates-only — it hydrates ids from the /updates feed
| since `now − 14 days` and upserts them. No crawl, no --fresh, no skip-synced.
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
        '*api4.thetvdb.com/v4/series/*/extended*' => fn (Request $request) => str_contains($request->url(), '/series/434847/extended')
            ? Http::response(fixtureBytes('Catalog/tvdb/series_extended.json'))
            : Http::response('', 404),
        '*api4.thetvdb.com/v4/updates*' => fn (Request $request) => str_contains($request->url(), 'page=1')
            ? Http::response(fixtureBytes('Catalog/tvdb/updates_page2.json'))
            : Http::response(fixtureBytes('Catalog/tvdb/updates.json')),
    ]);
}

beforeEach(function (): void {
    Cache::flush();
    config(['services.tvdb.key' => 'test-key']);
});

it('hydrates ids from the updates feed and persists them', function (): void {
    // Arrange
    fakeTvdbUpdates();

    // Act
    $this->artisan('tvdb:sync-shows');

    // Assert
    expect(Show::where('_tvdb_id', 81189)->first())->not->toBeNull();
});

it('queries /updates with since = now minus 14 days', function (): void {
    // Arrange
    $this->travelTo(now());
    fakeTvdbUpdates();

    // Act
    $this->artisan('tvdb:sync-shows');

    // Assert
    Http::assertSent(fn (Request $request): bool => str_contains(urldecode((string) $request->url()), 'since='.now()->subDays(14)->timestamp));
});

it('uses the updates feed only and never crawls /series?page', function (): void {
    // Arrange
    fakeTvdbUpdates();

    // Act
    $this->artisan('tvdb:sync-shows');

    // Assert
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/updates'));
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/series?page'));
});

it('caps hydrate calls with --limit', function (): void {
    // Arrange
    fakeTvdbUpdates();

    // Act
    $this->artisan('tvdb:sync-shows', ['--limit' => 1]);

    // Assert
    $hydrateCalls = 0;
    Http::assertSent(function (Request $request) use (&$hydrateCalls): bool {
        if (str_contains($request->url(), '/extended')) {
            $hydrateCalls++;
        }

        return true;
    });
    expect($hydrateCalls)->toBe(1);
});

it('re-hydrates an already-synced show that appears in the window', function (): void {
    // Arrange
    Show::factory()->create(['_tvdb_id' => 434847, 'tvdb_synced_at' => now()]);
    fakeTvdbUpdates();

    // Act
    $this->artisan('tvdb:sync-shows');

    // Assert
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/series/434847/extended'));
});

it('exits SUCCESS', function (): void {
    // Arrange
    fakeTvdbUpdates();

    // Act & Assert
    $this->artisan('tvdb:sync-shows')->assertExitCode(0);
});
