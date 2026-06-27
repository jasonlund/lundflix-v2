<?php

declare(strict_types=1);

use App\Domains\Catalog\Exceptions\TvdbRequestFailed;
use App\Domains\Catalog\Services\TvdbApiService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

/*
|--------------------------------------------------------------------------
| TheTVDB v4 service — allSeries() page-at-a-time crawl
|--------------------------------------------------------------------------
| allSeries(int $page = 0) GETs /series?page={n} and returns that page's
| `data[]` array of BASE series records (not extended). Unlike episodes()/
| updates(), it does NOT walk links.next — the caller advances the page. An
| empty `data` is the crawl terminus → []. A non-404 failure throws
| TvdbRequestFailed (a 404 decodes to null/empty, never reaching here).
|
| Fixtures (byte-exact real captures of /series):
|   series_page1.json — page=0, 500 records, first id 70327, links.next set
|   series_page2.json — page=1, 500 records, first id 70909
|   series_empty.json — past the end: data [], links.next null
|   login.json — data.token = test.jwt.token
|
| Every fake map ALSO answers /login because Http::preventStrayRequests()
| is global.
*/

describe('allSeries() page crawl', function (): void {
    beforeEach(function (): void {
        Cache::flush();
        config(['services.tvdb.key' => 'test-key']);
    });

    it('returns a page of base series records', function (): void {
        Http::fake([
            '*api4.thetvdb.com/v4/login*' => Http::response(fixtureBytes('Catalog/tvdb/login.json')),
            '*api4.thetvdb.com/v4/series?page=0*' => Http::response(fixtureBytes('Catalog/tvdb/series_page1.json')),
        ]);

        $result = resolve(TvdbApiService::class)->allSeries(0);

        expect($result)->toBeArray()->toHaveCount(500)
            ->and($result[0]['id'])->toBe(70327)
            ->and($result[0]['name'])->toBe('Buffy the Vampire Slayer');
    });

    it('returns the requested page, advancing page 0 → page 1', function (): void {
        Http::fake([
            '*api4.thetvdb.com/v4/login*' => Http::response(fixtureBytes('Catalog/tvdb/login.json')),
            '*api4.thetvdb.com/v4/series?page=1*' => Http::response(fixtureBytes('Catalog/tvdb/series_page2.json')),
        ]);

        $result = resolve(TvdbApiService::class)->allSeries(1);

        expect($result)->toBeArray()->toHaveCount(500)
            ->and($result[0]['id'])->toBe(70909);
    });

    it('returns [] when the page has no data (crawl terminus)', function (): void {
        Http::fake([
            '*api4.thetvdb.com/v4/login*' => Http::response(fixtureBytes('Catalog/tvdb/login.json')),
            '*api4.thetvdb.com/v4/series?page=99*' => Http::response(fixtureBytes('Catalog/tvdb/series_empty.json')),
        ]);

        $result = resolve(TvdbApiService::class)->allSeries(99);

        expect($result)->toBe([]);
    });

    it('throws TvdbRequestFailed on a non-404 error status', function (): void {
        Sleep::fake();
        Http::fake([
            '*api4.thetvdb.com/v4/login*' => Http::response(fixtureBytes('Catalog/tvdb/login.json')),
            '*api4.thetvdb.com/v4/series?page=0*' => Http::response('', 500),
        ]);

        expect(fn () => resolve(TvdbApiService::class)->allSeries(0))->toThrow(TvdbRequestFailed::class);
    });
});
