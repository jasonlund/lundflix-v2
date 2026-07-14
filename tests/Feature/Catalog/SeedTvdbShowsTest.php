<?php

declare(strict_types=1);

use App\Domains\Catalog\Exceptions\TvdbRequestFailed;
use App\Domains\Catalog\Models\Show;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Fixtures (byte-exact real TheTVDB v4 slices)
|--------------------------------------------------------------------------
| tests/Fixtures/Catalog/tvdb/login.json — POST /login → data.token JWT;
|   every fake map answers it because Http::preventStrayRequests() is global
|   and the JWT is fetched (and cached) before any /series call.
| tests/Fixtures/Catalog/tvdb/series_page1.json — GET /series?page=0, 500 BASE
|   records, first id 70327, links.next set (the crawl's first page).
| tests/Fixtures/Catalog/tvdb/series_empty.json — GET past the end, data [],
|   links.next null (the crawl terminus for page 1).
| tests/Fixtures/Catalog/tvdb/series_extended.json — GET /series/{id}/extended
|   (wrapped {status,data}); the extended Breaking Bad payload, data.id 81189,
|   data.name 'Breaking Bad', data.artworks 343 entries (109 mapped to media).
|
| 81189 is in NONE of the crawl fixtures, so the fake serves the extended
| payload for exactly ONE discovered id and 404s every other /extended — that
| one success upserts the show as _tvdb_id 81189. The crawl fake serves it for
| the crawled id 70327.
*/

function fakeTvdbSeedCrawl(): void
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

function fakeTvdbSeedCrawlWithMalformedId(): void
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

function fakeTvdbSeedCrawlWithMissingId(): void
{
    // A record omitting the `id` key can't occur in the real byte-exact page
    // fixtures, so this synthetic page injects one to prove the crawl skips it
    // without raising an "Undefined array key" warning per malformed record.
    $missingIdPage = json_encode([
        'status' => 'success',
        'data' => [
            ['name' => 'No Id Key'],
            ['id' => 70327, 'name' => 'Valid'],
        ],
        'links' => ['prev' => null, 'self' => null, 'next' => null, 'total_items' => 2, 'page_size' => 500],
    ]);

    Http::fake([
        '*api4.thetvdb.com/v4/login*' => Http::response(fixtureBytes('Catalog/tvdb/login.json')),
        '*api4.thetvdb.com/v4/series?page=0*' => Http::response($missingIdPage),
        '*api4.thetvdb.com/v4/series?page=1*' => Http::response(fixtureBytes('Catalog/tvdb/series_empty.json')),
        '*api4.thetvdb.com/v4/series/*/extended*' => fn (Request $request) => str_contains($request->url(), '/series/70327/extended')
            ? Http::response(fixtureBytes('Catalog/tvdb/series_extended.json'))
            : Http::response('', 404),
    ]);
}

beforeEach(function (): void {
    Cache::flush();
    config(['services.tvdb.key' => 'test-key']);
});

it('crawls allSeries pages and persists hydrated shows with _tvdb_ columns', function (): void {
    // Arrange
    fakeTvdbSeedCrawl();

    // Act
    $this->artisan('tvdb:seed-shows');

    // Assert
    $show = Show::where('_tvdb_id', 81189)->first();
    expect($show)->not->toBeNull();
    expect($show->_tvdb_name)->toBe('Breaking Bad');
});

it('persists the hydrated series artworks into media', function (): void {
    // Arrange
    fakeTvdbSeedCrawl();

    // Act
    $this->artisan('tvdb:seed-shows');

    // Assert
    $show = Show::where('_tvdb_id', 81189)->firstOrFail();
    expect($show->media()->where('is_active', true)->count())->toBeGreaterThan(0);
});

it('caps hydrate calls and stops paging with --limit', function (): void {
    // Arrange
    fakeTvdbSeedCrawl();

    // Act
    $this->artisan('tvdb:seed-shows', ['--limit' => 1]);

    // Assert
    $hydrateCalls = 0;
    Http::assertSent(function (Request $request) use (&$hydrateCalls): bool {
        if (str_contains($request->url(), '/extended')) {
            $hydrateCalls++;
        }

        return true;
    });
    expect($hydrateCalls)->toBe(1);
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/series?page=1'));
});

it('hydrates nothing with --limit=0', function (): void {
    // Arrange
    fakeTvdbSeedCrawl();

    // Act
    $this->artisan('tvdb:seed-shows', ['--limit' => 0]);

    // Assert
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/extended'));
});

it('skips a non-numeric crawl id without firing /series/0/extended', function (): void {
    // Arrange
    fakeTvdbSeedCrawlWithMalformedId();

    // Act
    $this->artisan('tvdb:seed-shows');

    // Assert
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/series/0/extended'));
});

it('skips a crawl record missing its id key without raising a warning', function (): void {
    // Arrange
    fakeTvdbSeedCrawlWithMissingId();

    // Act
    $this->artisan('tvdb:seed-shows');

    // Assert
    expect(Show::where('_tvdb_id', 81189)->first())->not->toBeNull();
});

it('exits SUCCESS', function (): void {
    // Arrange
    fakeTvdbSeedCrawl();

    // Act & Assert
    $this->artisan('tvdb:seed-shows')->assertExitCode(0);
});

it('announces it is starting before the pipeline runs', function (): void {
    // Arrange
    fakeTvdbSeedCrawl();

    // Act & Assert
    $this->artisan('tvdb:seed-shows')->expectsOutputToContain('Syncing shows…');
});

it('persists an id that fails its first hydrate then succeeds on the retry pass', function (): void {
    // Arrange
    // An undecodable 200 is non-retryable, so the crawl pass fails 70327 with exactly one
    // request; the retry pass then serves the valid extended body and heals it to 81189.
    Http::fake([
        '*api4.thetvdb.com/v4/login*' => Http::response(fixtureBytes('Catalog/tvdb/login.json')),
        '*api4.thetvdb.com/v4/series?page=0*' => Http::response(fixtureBytes('Catalog/tvdb/series_page1.json')),
        '*api4.thetvdb.com/v4/series?page=1*' => Http::response(fixtureBytes('Catalog/tvdb/series_empty.json')),
        '*api4.thetvdb.com/v4/series/70327/extended*' => Http::sequence()
            ->push('not json', 200)
            ->push(fixtureBytes('Catalog/tvdb/series_extended.json'), 200),
        '*api4.thetvdb.com/v4/series/*/extended*' => Http::response('', 404),
    ]);

    // Act
    $this->artisan('tvdb:seed-shows');

    // Assert
    expect(Show::where('_tvdb_id', 81189)->first())->not->toBeNull();
});

it('reports an id that fails both the crawl pass and the retry pass', function (): void {
    // Arrange
    Sleep::fake();
    Exceptions::fake();
    Http::fake([
        '*api4.thetvdb.com/v4/login*' => Http::response(fixtureBytes('Catalog/tvdb/login.json')),
        '*api4.thetvdb.com/v4/series?page=0*' => Http::response(fixtureBytes('Catalog/tvdb/series_page1.json')),
        '*api4.thetvdb.com/v4/series?page=1*' => Http::response(fixtureBytes('Catalog/tvdb/series_empty.json')),
        '*api4.thetvdb.com/v4/series/70327/extended*' => Http::response('', 500),
        '*api4.thetvdb.com/v4/series/*/extended*' => Http::response('', 404),
    ]);

    // Act
    $this->artisan('tvdb:seed-shows');

    // Assert
    Exceptions::assertReported(fn (TvdbRequestFailed $e): bool => str_contains($e->getMessage(), '70327'));
});

it('prints an end-of-run summary line naming the still-failing ids', function (): void {
    // Arrange
    Sleep::fake();
    Http::fake([
        '*api4.thetvdb.com/v4/login*' => Http::response(fixtureBytes('Catalog/tvdb/login.json')),
        '*api4.thetvdb.com/v4/series?page=0*' => Http::response(fixtureBytes('Catalog/tvdb/series_page1.json')),
        '*api4.thetvdb.com/v4/series?page=1*' => Http::response(fixtureBytes('Catalog/tvdb/series_empty.json')),
        '*api4.thetvdb.com/v4/series/70327/extended*' => Http::response('', 500),
        '*api4.thetvdb.com/v4/series/*/extended*' => Http::response('', 404),
    ]);

    // Act & Assert
    $this->artisan('tvdb:seed-shows')
        ->expectsOutputToContain('still failing')
        ->expectsOutputToContain('70327')
        ->assertExitCode(0);
});
