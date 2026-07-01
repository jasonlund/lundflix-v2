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
| tests/Fixtures/Catalog/tvdb/login.json — POST /login → data.token JWT;
|   every fake map answers it because Http::preventStrayRequests() is global
|   and the JWT is fetched (and cached) before any /series or /updates call.
| tests/Fixtures/Catalog/tvdb/series_page1.json — GET /series?page=0, 500 BASE
|   records, first id 70327, links.next set (the --fresh crawl's first page).
| tests/Fixtures/Catalog/tvdb/series_empty.json — GET past the end, data [],
|   links.next null (the crawl terminus for page 1).
| tests/Fixtures/Catalog/tvdb/updates.json + updates_page2.json — the /updates
|   feed, chained p0 → p1 → null via links.next; each record's series id is
|   `recordId` (recordIds 434847, 469484, 372030, then 470158, 371782, 479253).
| tests/Fixtures/Catalog/tvdb/series_extended.json — GET /series/{id}/extended
|   (wrapped {status,data}); the extended Breaking Bad payload, data.id 81189,
|   data.name 'Breaking Bad', data.artworks 343 entries (109 mapped to media).
|
| 81189 is in NONE of the crawl/updates fixtures, so the fake serves the
| extended payload for exactly ONE discovered id and 404s every other
| /extended (mirroring SyncTmdbMovies serving /movie/603 only) — that one
| success upserts the show as _tvdb_id 81189. The crawl fake serves it for
| crawled id 70327; the updates fake serves it for update id 434847.
*/

function fakeTvdbCrawl(): void
{
    Http::fake([
        '*api4.thetvdb.com/v4/login*' => Http::response(fixtureBytes('Catalog/tvdb/login.json')),
        '*api4.thetvdb.com/v4/series?page=0*' => Http::response(fixtureBytes('Catalog/tvdb/series_page1.json')),
        '*api4.thetvdb.com/v4/series?page=1*' => Http::response(fixtureBytes('Catalog/tvdb/series_empty.json')),
        '*api4.thetvdb.com/v4/series/*/extended*' => fn (Request $request) => str_contains($request->url(), '/series/70327/extended')
            ? Http::response(fixtureBytes('Catalog/tvdb/series_extended.json'))
            : Http::response('', 404),
    ]);
}

function fakeTvdbCrawlWithMalformedId(): void
{
    // A non-numeric export id can't occur in the real byte-exact page fixtures, so
    // this synthetic page injects one to prove the crawl skips it rather than
    // casting it to 0 and firing a wasted /series/0/extended hydration.
    $malformedPage = json_encode([
        'status' => 'success',
        'data' => [
            ['id' => 'not-a-number', 'name' => 'Malformed'],
            ['id' => 70327, 'name' => 'Valid'],
        ],
        'links' => ['prev' => null, 'self' => null, 'next' => null, 'total_items' => 2, 'page_size' => 500],
    ]);

    Http::fake([
        '*api4.thetvdb.com/v4/login*' => Http::response(fixtureBytes('Catalog/tvdb/login.json')),
        '*api4.thetvdb.com/v4/series?page=0*' => Http::response($malformedPage),
        '*api4.thetvdb.com/v4/series?page=1*' => Http::response(fixtureBytes('Catalog/tvdb/series_empty.json')),
        '*api4.thetvdb.com/v4/series/*/extended*' => fn (Request $request) => str_contains($request->url(), '/series/70327/extended')
            ? Http::response(fixtureBytes('Catalog/tvdb/series_extended.json'))
            : Http::response('', 404),
    ]);
}

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

it('crawls allSeries pages and persists hydrated shows with _tvdb_ columns on --fresh', function (): void {
    // Arrange
    fakeTvdbCrawl();

    // Act
    $this->artisan('tvdb:sync-shows', ['--fresh' => true]);

    // Assert
    $show = Show::where('_tvdb_id', 81189)->first();
    expect($show)->not->toBeNull();
    expect($show->_tvdb_name)->toBe('Breaking Bad');
});

it('persists the hydrated series artworks into media', function (): void {
    // Arrange
    fakeTvdbCrawl();

    // Act
    $this->artisan('tvdb:sync-shows', ['--fresh' => true]);

    // Assert
    $show = Show::where('_tvdb_id', 81189)->firstOrFail();
    expect($show->media()->where('is_active', true)->count())->toBeGreaterThan(0);
});

it('hydrates only ids from the updates feed on a default run', function (): void {
    // Arrange
    fakeTvdbUpdates();

    // Act
    $this->artisan('tvdb:sync-shows');

    // Assert
    expect(Show::where('_tvdb_id', 81189)->first())->not->toBeNull();
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/updates'));
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/series?page'));
});

it('caps hydrate calls with --limit', function (): void {
    // Arrange
    fakeTvdbCrawl();

    // Act
    $this->artisan('tvdb:sync-shows', ['--fresh' => true, '--limit' => 1]);

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

it('stops crawling once --limit ids are gathered without paging further', function (): void {
    // Arrange
    fakeTvdbCrawl();

    // Act
    $this->artisan('tvdb:sync-shows', ['--fresh' => true, '--limit' => 1]);

    // Assert
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/series?page=1'));
});

it('skips a non-numeric crawl id without hydrating it', function (): void {
    // Arrange
    fakeTvdbCrawlWithMalformedId();

    // Act
    $this->artisan('tvdb:sync-shows', ['--fresh' => true]);

    // Assert
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/series/0/extended'));
});

it('skips an already-synced show on a default run', function (): void {
    // Arrange
    Show::factory()->create(['_tvdb_id' => 434847, 'tvdb_synced_at' => now()]);
    fakeTvdbUpdates();

    // Act
    $this->artisan('tvdb:sync-shows');

    // Assert
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/series/434847/extended'));
});

it('reprocesses an already-synced show with --fresh', function (): void {
    // Arrange
    Show::factory()->create(['_tvdb_id' => 70327, 'tvdb_synced_at' => now()]);
    fakeTvdbCrawl();

    // Act
    $this->artisan('tvdb:sync-shows', ['--fresh' => true]);

    // Assert
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/series/70327/extended'));
});

it('exits SUCCESS', function (): void {
    // Arrange
    fakeTvdbCrawl();

    // Act & Assert
    $this->artisan('tvdb:sync-shows', ['--fresh' => true])->assertExitCode(0);
});
