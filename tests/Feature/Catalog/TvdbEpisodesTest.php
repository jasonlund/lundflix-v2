<?php

declare(strict_types=1);

use App\Domains\Catalog\Services\TvdbApiService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/*
|--------------------------------------------------------------------------
| TheTVDB v4 service — paginated episode listing (links.next walker)
|--------------------------------------------------------------------------
| episodes() GETs /series/{id}/episodes/{season-type}, follows TVDB's
| top-level `links.next` cursor until it is null, and returns every page's
| `data.episodes` records flattened in page order. (TVDB nests episode
| records at `data.episodes` — NOT `data`; `links.next` is top-level.)
|
| Fixtures (byte-exact real captures of series 71663 /episodes/default):
|   series_episodes_page1.json — links.next non-null, 3 episodes (first id 4350173)
|   series_episodes_page2.json — links.next null, 3 episodes (last id 420650)
|   login.json — data.token = test.jwt.token
|
| Every fake map ALSO answers /login because Http::preventStrayRequests()
| is global; multi-page walking is driven with Http::sequence() on the
| series path, mirroring the 401-then-200 / 500-then-200 sequence tests.
*/

describe('episodes() pagination', function (): void {
    beforeEach(function (): void {
        Cache::flush();
        config(['services.tvdb.key' => 'test-key']);
    });

    it('follows links.next to a second page', function (): void {
        Http::fake([
            '*api4.thetvdb.com/v4/login*' => Http::response(fixtureBytes('Catalog/tvdb/login.json')),
            '*api4.thetvdb.com/v4/series/*' => Http::sequence()
                ->push(fixtureBytes('Catalog/tvdb/series_episodes_page1.json'), 200)
                ->push(fixtureBytes('Catalog/tvdb/series_episodes_page2.json'), 200),
        ]);

        resolve(TvdbApiService::class)->episodes(71663);

        expect(Http::recorded(fn ($request): bool => str_contains((string) $request->url(), '/series/71663/episodes')))->toHaveCount(2);
    });

    it('flattens episode records across both pages in page order', function (): void {
        Http::fake([
            '*api4.thetvdb.com/v4/login*' => Http::response(fixtureBytes('Catalog/tvdb/login.json')),
            '*api4.thetvdb.com/v4/series/*' => Http::sequence()
                ->push(fixtureBytes('Catalog/tvdb/series_episodes_page1.json'), 200)
                ->push(fixtureBytes('Catalog/tvdb/series_episodes_page2.json'), 200),
        ]);

        $result = resolve(TvdbApiService::class)->episodes(71663);

        $page1 = json_decode(fixtureBytes('Catalog/tvdb/series_episodes_page1.json'), true)['data']['episodes'];
        $page2 = json_decode(fixtureBytes('Catalog/tvdb/series_episodes_page2.json'), true)['data']['episodes'];
        expect($result[0])->toBe($page1[0]);
        expect(end($result))->toBe(end($page2));
    });

    it('stops after one page when links.next is null', function (): void {
        Http::fake([
            '*api4.thetvdb.com/v4/login*' => Http::response(fixtureBytes('Catalog/tvdb/login.json')),
            '*api4.thetvdb.com/v4/series/*' => Http::response(fixtureBytes('Catalog/tvdb/series_episodes_page2.json')),
        ]);

        resolve(TvdbApiService::class)->episodes(71663);

        expect(Http::recorded(fn ($request): bool => str_contains((string) $request->url(), '/series/71663/episodes')))->toHaveCount(1);
    });
});

describe('episodes() season-type & empty results', function (): void {
    beforeEach(function (): void {
        Cache::flush();
        config(['services.tvdb.key' => 'test-key']);
    });

    it('GETs /series/{id}/episodes/default by default', function (): void {
        Http::fake([
            '*api4.thetvdb.com/v4/login*' => Http::response(fixtureBytes('Catalog/tvdb/login.json')),
            '*api4.thetvdb.com/v4/series/*' => Http::response(fixtureBytes('Catalog/tvdb/series_episodes_page2.json')),
        ]);

        resolve(TvdbApiService::class)->episodes(81189);

        Http::assertSent(fn ($request): bool => str_contains((string) $request->url(), '/series/81189/episodes/default'));
    });

    it('GETs the requested season-type as a path segment', function (): void {
        Http::fake([
            '*api4.thetvdb.com/v4/login*' => Http::response(fixtureBytes('Catalog/tvdb/login.json')),
            '*api4.thetvdb.com/v4/series/*' => Http::response(fixtureBytes('Catalog/tvdb/series_episodes_page2.json')),
        ]);

        resolve(TvdbApiService::class)->episodes(81189, 'official');

        Http::assertSent(fn ($request): bool => str_contains((string) $request->url(), '/series/81189/episodes/official'));
    });

    it('returns an empty array when the series episodes 404', function (): void {
        Http::fake([
            '*api4.thetvdb.com/v4/login*' => Http::response(fixtureBytes('Catalog/tvdb/login.json')),
            '*api4.thetvdb.com/v4/series/*' => Http::response('', 404),
        ]);

        $result = resolve(TvdbApiService::class)->episodes(81189);

        expect($result)->toBe([]);
    });

    it('returns an empty array when a page carries no episode data', function (): void {
        Http::fake([
            '*api4.thetvdb.com/v4/login*' => Http::response(fixtureBytes('Catalog/tvdb/login.json')),
            '*api4.thetvdb.com/v4/series/*' => Http::response(fixtureBytes('Catalog/tvdb/series_episodes_empty.json')),
        ]);

        $result = resolve(TvdbApiService::class)->episodes(81189);

        expect($result)->toBe([]);
    });
});
