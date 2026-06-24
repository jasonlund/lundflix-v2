<?php

declare(strict_types=1);

use App\Domains\Catalog\Services\TvdbApiService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/*
|--------------------------------------------------------------------------
| TheTVDB v4 service — updates() feed (links.next walker)
|--------------------------------------------------------------------------
| updates() GETs /updates?since={ts}&type={…}, follows TVDB's top-level
| `links.next` cursor until it is null, and returns every page's `data`
| EntityUpdate records flattened in page order — full record shape kept,
| NOT reduced to bare ids.
|
| Fixtures (byte-exact real captures of /updates):
|   updates.json       — links.next non-null, 3 records (434847, 469484, 372030)
|   updates_page2.json — links.next null, 3 records (470158, 371782, and the
|                        merge-delete record 479253 carrying mergeToId=467423,
|                        mergeToType="series", method="delete", entityType="series")
|   login.json — data.token = test.jwt.token
|
| Every fake map ALSO answers /login because Http::preventStrayRequests()
| is global; multi-page walking is driven with Http::sequence() on the
| updates path, mirroring TvdbEpisodesTest's series sequence.
*/

describe('updates() feed', function (): void {
    beforeEach(function (): void {
        Cache::flush();
        config(['services.tvdb.key' => 'test-key']);
    });

    it('GETs /updates with the since and type query params', function (): void {
        Http::fake([
            '*api4.thetvdb.com/v4/login*' => Http::response(fixtureBytes('Catalog/tvdb/login.json')),
            '*api4.thetvdb.com/v4/updates*' => Http::response(fixtureBytes('Catalog/tvdb/updates_page2.json')),
        ]);

        resolve(TvdbApiService::class)->updates(1781503200, 'series');

        Http::assertSent(fn ($request): bool => str_contains(urldecode((string) $request->url()), '/updates?since=1781503200&type=series'));
    });

    it('walks links.next and flattens update records across all pages', function (): void {
        Http::fake([
            '*api4.thetvdb.com/v4/login*' => Http::response(fixtureBytes('Catalog/tvdb/login.json')),
            '*api4.thetvdb.com/v4/updates*' => Http::sequence()
                ->push(fixtureBytes('Catalog/tvdb/updates.json'), 200)
                ->push(fixtureBytes('Catalog/tvdb/updates_page2.json'), 200),
        ]);

        $result = resolve(TvdbApiService::class)->updates(1781503200, 'series');

        expect(array_column($result, 'recordId'))->toContain(434847, 479253);
    });

    it('returns the single page when links.next is null', function (): void {
        Http::fake([
            '*api4.thetvdb.com/v4/login*' => Http::response(fixtureBytes('Catalog/tvdb/login.json')),
            '*api4.thetvdb.com/v4/updates*' => Http::response(fixtureBytes('Catalog/tvdb/updates_page2.json')),
        ]);

        $result = resolve(TvdbApiService::class)->updates(1781503200, 'series');

        expect(Http::recorded(fn ($request): bool => str_contains((string) $request->url(), '/updates')))->toHaveCount(1);
        expect($result)->toBe(json_decode(fixtureBytes('Catalog/tvdb/updates_page2.json'), true)['data']);
    });

    it('preserves full update records carrying merge metadata, not bare ids', function (): void {
        Http::fake([
            '*api4.thetvdb.com/v4/login*' => Http::response(fixtureBytes('Catalog/tvdb/login.json')),
            '*api4.thetvdb.com/v4/updates*' => Http::response(fixtureBytes('Catalog/tvdb/updates_page2.json')),
        ]);

        $result = resolve(TvdbApiService::class)->updates(1781503200, 'series');

        $merge = collect($result)->firstWhere('recordId', 479253);
        expect($merge)->toHaveKeys(['recordId', 'method', 'entityType', 'mergeToId', 'mergeToType']);
    });
});
