<?php

declare(strict_types=1);

use App\Domains\Catalog\Exceptions\TvdbAuthenticationFailed;
use App\Domains\Catalog\Exceptions\TvdbRequestFailed;
use App\Domains\Catalog\Services\TvdbApiService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

/*
|--------------------------------------------------------------------------
| TheTVDB v4 service — seriesMany() (pooled batch /series/{id}/extended)
|--------------------------------------------------------------------------
| seriesMany(array $ids) fires one request per id via Http::pool() and returns
| a PooledResult { results, failedIds }: results is the [tvdb id => array|null]
| map keyed by the input id, preserving input order; failedIds lists the ids
| whose requests failed past retries. A per-id 404 yields null for that id
| without sinking its siblings; repeated ids de-dupe to one request per unique
| id; ids fan out at most `services.tvdb.concurrency` requests per chunk in
| input order. Request failures past retries are collected on failedIds and
| report()ed together as a single TvdbRequestFailed naming every failed id,
| while the succeeding ids are still RETURNED on results (a transient per-id
| failure never drops the batch's good rows); a 401 is fatal for the whole
| batch and throws TvdbAuthenticationFailed.
|
| Fixtures (byte-exact real captures; never hand-fabricated):
|   series_extended.json — real /series/{id}/extended body. Reused as the
|       response body for every faked id (Http::fake() matches by URL, not pool
|       key), so each id gets a distinct per-id url pattern returning this body.
|   login.json — data.token = test.jwt.token.
|
| Http::preventStrayRequests() is GLOBAL, and the cached JWT is fetched via
| POST /login, so EVERY fake map ALSO answers '*api4.thetvdb.com/v4/login*' or
| the login call is a stray request and the test fails for the wrong reason.
| Cache::flush() in beforeEach prevents the long-lived JWT bleeding across
| tests; config() sets the apikey the login exchanges.
*/

describe('seriesMany()', function (): void {
    beforeEach(function (): void {
        Cache::flush();
        config(['services.tvdb.key' => 'test-key']);
        Cache::put('tvdb.jwt', 'test.jwt.token', now()->addDay());
    });

    it('returns a series map keyed by the input tvdb ids hitting /series/{id}/extended', function (): void {
        Http::fake([
            '*api4.thetvdb.com/v4/login*' => Http::response(fixtureBytes('Catalog/tvdb/login.json')),
            '*/series/121361/extended*' => Http::response(fixtureBytes('Catalog/tvdb/series_extended.json')),
            '*/series/305288/extended*' => Http::response(fixtureBytes('Catalog/tvdb/series_extended.json')),
        ]);

        $result = resolve(TvdbApiService::class)->seriesMany([121361, 305288]);

        $body = json_decode(fixtureBytes('Catalog/tvdb/series_extended.json'), true);
        expect(array_keys($result->results))->toBe([121361, 305288])
            ->and($result->results[121361])->toBe($body)
            ->and($result->results[305288])->toBe($body);
        Http::assertSent(fn ($request): bool => str_contains((string) $request->url(), '/series/121361/extended'));
    });

    it('yields null for a 404 series id while others still resolve', function (): void {
        Http::fake([
            '*api4.thetvdb.com/v4/login*' => Http::response(fixtureBytes('Catalog/tvdb/login.json')),
            '*/series/121361/extended*' => Http::response(fixtureBytes('Catalog/tvdb/series_extended.json')),
            '*/series/999/extended*' => Http::response('', 404),
        ]);

        $result = resolve(TvdbApiService::class)->seriesMany([121361, 999]);

        expect($result->results)->toBe([
            121361 => json_decode(fixtureBytes('Catalog/tvdb/series_extended.json'), true),
            999 => null,
        ])->and($result->failedIds)->toBe([]);
    });

    it('de-duplicates repeated series ids, firing one request per unique id and keying first-seen', function (): void {
        Http::fake([
            '*api4.thetvdb.com/v4/login*' => Http::response(fixtureBytes('Catalog/tvdb/login.json')),
            '*/series/121361/extended*' => Http::response(fixtureBytes('Catalog/tvdb/series_extended.json')),
            '*/series/305288/extended*' => Http::response(fixtureBytes('Catalog/tvdb/series_extended.json')),
        ]);

        $result = resolve(TvdbApiService::class)->seriesMany([121361, 121361, 305288]);

        $extendedSent = collect(Http::recorded())->filter(
            fn ($pair): bool => str_contains((string) $pair[0]->url(), '/extended')
        );
        expect($extendedSent)->toHaveCount(2)
            ->and(array_keys($result->results))->toBe([121361, 305288])
            ->and($result->failedIds)->toBe([]);
    });

    it('fires one request per id and preserves input order across multiple concurrency-sized chunks', function (): void {
        config(['services.tvdb.concurrency' => 3]);
        Http::fake([
            '*api4.thetvdb.com/v4/login*' => Http::response(fixtureBytes('Catalog/tvdb/login.json')),
            '*/series/*/extended*' => Http::response(fixtureBytes('Catalog/tvdb/series_extended.json')),
        ]);

        resolve(TvdbApiService::class)->seriesMany([1, 2, 3, 4, 5, 6, 7]);

        Http::assertSentInOrder([
            fn ($request): bool => str_contains((string) $request->url(), '/series/1/extended'),
            fn ($request): bool => str_contains((string) $request->url(), '/series/2/extended'),
            fn ($request): bool => str_contains((string) $request->url(), '/series/3/extended'),
            fn ($request): bool => str_contains((string) $request->url(), '/series/4/extended'),
            fn ($request): bool => str_contains((string) $request->url(), '/series/5/extended'),
            fn ($request): bool => str_contains((string) $request->url(), '/series/6/extended'),
            fn ($request): bool => str_contains((string) $request->url(), '/series/7/extended'),
        ]);
    });

    it('returns the succeeding id and reports every failed id when multiple series requests fail past retries', function (): void {
        // Arrange
        Sleep::fake();
        Exceptions::fake();
        Http::fake([
            '*api4.thetvdb.com/v4/login*' => Http::response(fixtureBytes('Catalog/tvdb/login.json')),
            '*/series/121361/extended*' => Http::response('', 500),
            '*/series/305288/extended*' => Http::response(fixtureBytes('Catalog/tvdb/series_extended.json')),
            '*/series/424242/extended*' => Http::response('', 500),
        ]);

        // Act
        $result = resolve(TvdbApiService::class)->seriesMany([121361, 305288, 424242]);

        // Assert
        expect($result->results)->toBe([305288 => json_decode(fixtureBytes('Catalog/tvdb/series_extended.json'), true)])
            ->and($result->failedIds)->toEqualCanonicalizing([121361, 424242]);
        Exceptions::assertReported(
            fn (TvdbRequestFailed $e): bool => str_contains($e->getMessage(), '121361') && str_contains($e->getMessage(), '424242')
        );
    });

    it('returns the succeeding id and reports a 5xx id and an undecodable-200 id together in one aggregate failure', function (): void {
        // Arrange
        Sleep::fake();
        Exceptions::fake();
        Http::fake([
            '*api4.thetvdb.com/v4/login*' => Http::response(fixtureBytes('Catalog/tvdb/login.json')),
            '*/series/121361/extended*' => Http::response(fixtureBytes('Catalog/tvdb/series_extended.json')),
            '*/series/305288/extended*' => Http::response('', 500),
            '*/series/424242/extended*' => Http::response('not json', 200),
        ]);

        // Act
        $result = resolve(TvdbApiService::class)->seriesMany([121361, 305288, 424242]);

        // Assert
        expect($result->results)->toBe([121361 => json_decode(fixtureBytes('Catalog/tvdb/series_extended.json'), true)])
            ->and($result->failedIds)->toEqualCanonicalizing([305288, 424242]);
        Exceptions::assertReported(
            fn (TvdbRequestFailed $e): bool => str_contains($e->getMessage(), '305288') && str_contains($e->getMessage(), '424242')
        );
    });

    it('throws TvdbAuthenticationFailed when one series id in the batch returns 401', function (): void {
        Http::fake([
            '*api4.thetvdb.com/v4/login*' => Http::response(fixtureBytes('Catalog/tvdb/login.json')),
            '*/series/121361/extended*' => Http::response('', 401),
            '*/series/305288/extended*' => Http::response(fixtureBytes('Catalog/tvdb/series_extended.json')),
        ]);

        $call = fn () => resolve(TvdbApiService::class)->seriesMany([121361, 305288]);

        expect($call)->toThrow(TvdbAuthenticationFailed::class);
    });

    it('forgets the cached jwt when a series id in the batch returns 401 so a retry re-authenticates', function (): void {
        Http::fake([
            '*api4.thetvdb.com/v4/login*' => Http::response(fixtureBytes('Catalog/tvdb/login.json')),
            '*/series/121361/extended*' => Http::response('', 401),
            '*/series/305288/extended*' => Http::response(fixtureBytes('Catalog/tvdb/series_extended.json')),
        ]);

        try {
            resolve(TvdbApiService::class)->seriesMany([121361, 305288]);
        } catch (TvdbAuthenticationFailed) {
        }

        expect(Cache::get('tvdb.jwt'))->toBeNull();
    });
});
