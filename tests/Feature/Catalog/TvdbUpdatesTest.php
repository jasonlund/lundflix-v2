<?php

declare(strict_types=1);

use App\Domains\Catalog\Services\TvdbApiService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| TheTVDB v4 service — updates() feed (streaming links.next walker)
|--------------------------------------------------------------------------
| updates() GETs /updates?since={ts}&type={…} and yields each page's `data`
| EntityUpdate records one at a time as TVDB's top-level `links.next` cursor
| advances: nothing is fetched until the caller iterates, one page is held at
| a time, and no further page is fetched once the caller stops — full record
| shape kept, NOT reduced to bare ids.
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

        iterator_to_array(resolve(TvdbApiService::class)->updates(1781503200, 'series'));

        Http::assertSent(fn ($request): bool => Str::contains(urldecode((string) $request->url()), '/updates?since=1781503200&type=series'));
    });

    it('yields every update record across the links.next walk', function (): void {
        Http::fake([
            '*api4.thetvdb.com/v4/login*' => Http::response(fixtureBytes('Catalog/tvdb/login.json')),
            '*api4.thetvdb.com/v4/updates*' => Http::sequence()
                ->push(fixtureBytes('Catalog/tvdb/updates.json'), 200)
                ->push(fixtureBytes('Catalog/tvdb/updates_page2.json'), 200),
        ]);

        $records = iterator_to_array(resolve(TvdbApiService::class)->updates(1781503200, 'series'));

        // Key-preserving on purpose (no `preserve_keys: false`): a `yield from
        // $page['data']` yields the inner array's own 0,1,2 keys, so page 2
        // silently overwrites page 1 and only 3 records survive. Pinning 6 here
        // is what forces a per-record `yield`.
        expect($records)->toHaveCount(6);
        expect(array_column($records, 'recordId'))->toContain(434847, 479253);
    });

    it('yields the single page when links.next is null', function (): void {
        Http::fake([
            '*api4.thetvdb.com/v4/login*' => Http::response(fixtureBytes('Catalog/tvdb/login.json')),
            '*api4.thetvdb.com/v4/updates*' => Http::response(fixtureBytes('Catalog/tvdb/updates_page2.json')),
        ]);

        $records = iterator_to_array(resolve(TvdbApiService::class)->updates(1781503200, 'series'));

        expect(Http::recorded(fn ($request): bool => Str::contains((string) $request->url(), '/updates')))->toHaveCount(1);
        expect($records)->toBe(json_decode(fixtureBytes('Catalog/tvdb/updates_page2.json'), true)['data']);
    });

    it('preserves full update records carrying merge metadata, not bare ids', function (): void {
        Http::fake([
            '*api4.thetvdb.com/v4/login*' => Http::response(fixtureBytes('Catalog/tvdb/login.json')),
            '*api4.thetvdb.com/v4/updates*' => Http::response(fixtureBytes('Catalog/tvdb/updates_page2.json')),
        ]);

        $records = iterator_to_array(resolve(TvdbApiService::class)->updates(1781503200, 'series'));

        $merge = collect($records)->firstWhere('recordId', 479253);
        expect($merge)->toHaveKeys(['recordId', 'method', 'entityType', 'mergeToId', 'mergeToType']);
    });

    it('sends nothing until the caller starts iterating', function (): void {
        Http::fake([
            '*api4.thetvdb.com/v4/login*' => Http::response(fixtureBytes('Catalog/tvdb/login.json')),
            '*api4.thetvdb.com/v4/updates*' => Http::response(fixtureBytes('Catalog/tvdb/updates_page2.json')),
        ]);

        resolve(TvdbApiService::class)->updates(1781503200, 'series');

        // /login lives inside the private get(), so it defers with the first page.
        Http::assertNothingSent();
    });

    it('stops fetching pages once the caller stops iterating', function (): void {
        Http::fake([
            '*api4.thetvdb.com/v4/login*' => Http::response(fixtureBytes('Catalog/tvdb/login.json')),
            '*api4.thetvdb.com/v4/updates*' => Http::sequence()
                ->push(fixtureBytes('Catalog/tvdb/updates.json'), 200)
                ->push(fixtureBytes('Catalog/tvdb/updates_page2.json'), 200),
        ]);

        foreach (resolve(TvdbApiService::class)->updates(1781503200, 'series') as $record) {
            break;
        }

        expect(Http::recorded(fn ($request): bool => Str::contains((string) $request->url(), '/updates')))->toHaveCount(1);
    });
});
