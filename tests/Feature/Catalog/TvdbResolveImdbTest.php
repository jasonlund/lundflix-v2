<?php

declare(strict_types=1);

use App\Domains\Catalog\Services\TvdbApiService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/*
|--------------------------------------------------------------------------
| TheTVDB v4 service — resolveByImdbId() (IMDb -> series resolution)
|--------------------------------------------------------------------------
| resolveByImdbId() GETs /search/remoteid/{tt} and returns the raw `data[]`
| element whose member is `series` (e.g. ["series" => [...]]) — selecting the
| series member out of a possibly mixed result — null when no element carries a
| `series` key, and null on 404. It reuses the existing get()/decode() seams
| (JWT + 401 retry, 404 -> null), which are covered elsewhere and not retested.
|
| Fixtures:
|   search_remoteid.json — byte-exact real capture (GoT tt0944947);
|       data[0] has a `series` key (data count 1).
|   search_remoteid_no_series.json — byte-exact real capture (movie tt0094744);
|       data[0] has a `movie` key, no `series` (data count 1).
|   search_remoteid_multitype.json — SYNTHETIC: real series member (from
|       search_remoteid.json) and real movie member (from
|       search_remoteid_no_series.json) spliced verbatim into one `data[]`.
|       Real data never yields a multi-type data[] for a unique IMDb tt, so this
|       case can only exist synthetically; both members are unedited real bodies.
|   login.json — data.token = test.jwt.token.
|
| Every fake map ALSO answers /login because Http::preventStrayRequests() is
| global. The multi-type test locates the series entry by KEY, not index, to
| prove selection-from-a-mix rather than pass-through of data[0].
*/

describe('resolveByImdbId()', function (): void {
    beforeEach(function (): void {
        Cache::flush();
        config(['services.tvdb.key' => 'test-key']);
    });

    it('GETs /search/remoteid/{imdbId}', function (): void {
        Http::fake([
            '*api4.thetvdb.com/v4/login*' => Http::response(fixtureBytes('Catalog/tvdb/login.json')),
            '*api4.thetvdb.com/v4/search/remoteid/*' => Http::response(fixtureBytes('Catalog/tvdb/search_remoteid.json')),
        ]);

        resolve(TvdbApiService::class)->resolveByImdbId('tt0944947');

        Http::assertSent(fn ($request): bool => str_contains((string) $request->url(), '/search/remoteid/tt0944947'));
    });

    it('returns the series member from a multi-type data[]', function (): void {
        Http::fake([
            '*api4.thetvdb.com/v4/login*' => Http::response(fixtureBytes('Catalog/tvdb/login.json')),
            '*api4.thetvdb.com/v4/search/remoteid/*' => Http::response(fixtureBytes('Catalog/tvdb/search_remoteid_multitype.json')),
        ]);

        $result = resolve(TvdbApiService::class)->resolveByImdbId('tt0944947');

        $data = json_decode(fixtureBytes('Catalog/tvdb/search_remoteid_multitype.json'), true)['data'];
        $seriesEntry = collect($data)->first(fn (array $entry): bool => array_key_exists('series', $entry));
        expect($result)->toBe($seriesEntry);
    });

    it('returns null when no data member is a series', function (): void {
        Http::fake([
            '*api4.thetvdb.com/v4/login*' => Http::response(fixtureBytes('Catalog/tvdb/login.json')),
            '*api4.thetvdb.com/v4/search/remoteid/*' => Http::response(fixtureBytes('Catalog/tvdb/search_remoteid_no_series.json')),
        ]);

        $result = resolve(TvdbApiService::class)->resolveByImdbId('tt0094744');

        expect($result)->toBeNull();
    });

    it('returns null on 404', function (): void {
        Http::fake([
            '*api4.thetvdb.com/v4/login*' => Http::response(fixtureBytes('Catalog/tvdb/login.json')),
            '*api4.thetvdb.com/v4/search/remoteid/*' => Http::response('', 404),
        ]);

        $result = resolve(TvdbApiService::class)->resolveByImdbId('tt0944947');

        expect($result)->toBeNull();
    });
});
