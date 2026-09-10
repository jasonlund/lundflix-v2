<?php

declare(strict_types=1);

use App\Domains\Catalog\Exceptions\TvdbAuthenticationFailed;
use App\Domains\Catalog\Exceptions\TvdbRequestFailed;
use App\Domains\Catalog\Services\TvdbApiService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| TheTVDB v4 service — pooled batch fetches (seriesMany, episodesMany)
|--------------------------------------------------------------------------
| seriesMany(array $ids) fires one request per id through the shared pooled
| transport and returns a PooledResult { results, failedIds }: results is the
| [tvdb id => array|null] map keyed by the input id, preserving input order;
| failedIds lists the ids whose requests failed past retries. A per-id 404
| yields null for that id without sinking its siblings; repeated ids de-dupe to
| one request per unique id; ids fan out in input order through one rolling
| window at most `services.tvdb.concurrency` wide. Request failures past
| retries are collected on failedIds and report()ed together as a single
| TvdbRequestFailed naming every failed id, while the succeeding ids are still
| RETURNED on results (a transient per-id failure never drops the batch's good
| rows); a 401 is fatal for the whole batch and throws TvdbAuthenticationFailed.
|
| episodesMany(array $ids) is the same pooling over the episode-id endpoint:
| GET /episodes/{id} — the BASE path, NOT /extended and NOT the show-wide
| /series/{id}/episodes. Same PooledResult contract: input-ordered id => raw
| envelope map, null on a per-id 404, failedIds aggregated into one reported
| TvdbRequestFailed, a 401 fatal for the whole batch.
|
| Fixtures (byte-exact real captures; never hand-fabricated):
|   series_extended.json — real /series/{id}/extended body. Reused as the
|       response body for every faked id (Http::fake() matches by URL), so each
|       id gets a distinct per-id url pattern returning this body.
|   episode_{id}.json — real /episodes/{id} bodies for 9786562, 9786563
|       (seriesId 434847), 11846050, 11846051 (469484) and 9256455, 9256456
|       (371082). Each id has its own capture (unlike seriesMany's single reused
|       body) so a mis-keyed result map can't pass by returning an identical
|       payload. These are the exact recordIds carried by the committed
|       episode_updates.json feed captures, so the later feed-driven slices
|       fetch the same episodes these bodies describe.
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
        Http::assertSent(fn ($request): bool => Str::contains((string) $request->url(), '/series/121361/extended'));
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
            fn ($pair): bool => Str::contains((string) $pair[0]->url(), '/extended')
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
            fn ($request): bool => Str::contains((string) $request->url(), '/series/1/extended'),
            fn ($request): bool => Str::contains((string) $request->url(), '/series/2/extended'),
            fn ($request): bool => Str::contains((string) $request->url(), '/series/3/extended'),
            fn ($request): bool => Str::contains((string) $request->url(), '/series/4/extended'),
            fn ($request): bool => Str::contains((string) $request->url(), '/series/5/extended'),
            fn ($request): bool => Str::contains((string) $request->url(), '/series/6/extended'),
            fn ($request): bool => Str::contains((string) $request->url(), '/series/7/extended'),
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
            fn (TvdbRequestFailed $e): bool => Str::contains($e->getMessage(), '121361') && Str::contains($e->getMessage(), '424242')
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
            fn (TvdbRequestFailed $e): bool => Str::contains($e->getMessage(), '305288') && Str::contains($e->getMessage(), '424242')
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

describe('episodesMany()', function (): void {
    beforeEach(function (): void {
        Cache::flush();
        config(['services.tvdb.key' => 'test-key']);
        Cache::put('tvdb.jwt', 'test.jwt.token', now()->addDay());
    });

    it('returns an episode map keyed by the input tvdb ids hitting /episodes/{id}', function (): void {
        // Arrange
        Http::fake([
            '*api4.thetvdb.com/v4/login*' => Http::response(fixtureBytes('Catalog/tvdb/login.json')),
            '*/episodes/9786562*' => Http::response(fixtureBytes('Catalog/tvdb/episode_9786562.json')),
            '*/episodes/9786563*' => Http::response(fixtureBytes('Catalog/tvdb/episode_9786563.json')),
        ]);

        // Act
        $result = resolve(TvdbApiService::class)->episodesMany([9786562, 9786563]);

        // Assert
        expect(array_keys($result->results))->toBe([9786562, 9786563])
            ->and($result->results[9786562])->toBe(json_decode(fixtureBytes('Catalog/tvdb/episode_9786562.json'), true))
            ->and($result->results[9786563])->toBe(json_decode(fixtureBytes('Catalog/tvdb/episode_9786563.json'), true));
        Http::assertSent(fn ($request): bool => Str::contains((string) $request->url(), '/episodes/9786562'));
        // The point of the ticket: episodes are fetched by episode id off the BASE
        // path, never the extended one and never the show-wide episode listing.
        Http::assertNotSent(fn ($request): bool => Str::contains((string) $request->url(), '/extended'));
        Http::assertNotSent(fn ($request): bool => Str::contains((string) $request->url(), '/series/'));
    });

    it('yields null for a 404 episode id while its siblings still resolve', function (): void {
        // Arrange
        Http::fake([
            '*api4.thetvdb.com/v4/login*' => Http::response(fixtureBytes('Catalog/tvdb/login.json')),
            '*/episodes/11846050*' => Http::response(fixtureBytes('Catalog/tvdb/episode_11846050.json')),
            '*/episodes/999*' => Http::response('', 404),
        ]);

        // Act
        $result = resolve(TvdbApiService::class)->episodesMany([11846050, 999]);

        // Assert
        expect($result->results)->toBe([
            11846050 => json_decode(fixtureBytes('Catalog/tvdb/episode_11846050.json'), true),
            999 => null,
        ])->and($result->failedIds)->toBe([]);
    });

    it('returns the succeeding id and reports every failed id when episode requests fail past retries', function (): void {
        // Arrange
        Sleep::fake();
        Exceptions::fake();
        Http::fake([
            '*api4.thetvdb.com/v4/login*' => Http::response(fixtureBytes('Catalog/tvdb/login.json')),
            '*/episodes/9256455*' => Http::response('', 500),
            '*/episodes/9256456*' => Http::response(fixtureBytes('Catalog/tvdb/episode_9256456.json')),
            '*/episodes/11846051*' => Http::response('', 500),
        ]);

        // Act
        $result = resolve(TvdbApiService::class)->episodesMany([9256455, 9256456, 11846051]);

        // Assert
        expect($result->results)->toBe([9256456 => json_decode(fixtureBytes('Catalog/tvdb/episode_9256456.json'), true)])
            ->and($result->failedIds)->toEqualCanonicalizing([9256455, 11846051]);
        Exceptions::assertReported(
            fn (TvdbRequestFailed $e): bool => Str::contains($e->getMessage(), '9256455') && Str::contains($e->getMessage(), '11846051')
        );
    });

    it('throws TvdbAuthenticationFailed when one episode id in the batch returns 401', function (): void {
        // Arrange
        Http::fake([
            '*api4.thetvdb.com/v4/login*' => Http::response(fixtureBytes('Catalog/tvdb/login.json')),
            '*/episodes/9786562*' => Http::response('', 401),
            '*/episodes/9786563*' => Http::response(fixtureBytes('Catalog/tvdb/episode_9786563.json')),
        ]);

        // Act & Assert
        expect(fn () => resolve(TvdbApiService::class)->episodesMany([9786562, 9786563]))
            ->toThrow(TvdbAuthenticationFailed::class);
    });
});
