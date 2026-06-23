<?php

declare(strict_types=1);

use App\Domains\Catalog\Exceptions\TvdbAuthenticationFailed;
use App\Domains\Catalog\Exceptions\TvdbRequestFailed;
use App\Domains\Catalog\Services\TvdbApiService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

/*
|--------------------------------------------------------------------------
| TheTVDB v4 service — foundation test file
|--------------------------------------------------------------------------
| Mirrors tests/Feature/Catalog/TmdbApiServiceTest.php. This slice covers the
| config shape only (services.tvdb): a `key` bound to env TVDB_KEY and a
| `concurrency` defaulting to 10, with NO static JWT/token key (the JWT is a
| runtime-cached internal credential, never in config/env). Later slices grow
| this file with auth and fetch behavior.
*/

it('defaults services.tvdb.concurrency to 10', function (): void {
    putenv('TVDB_CONCURRENCY');
    unset($_ENV['TVDB_CONCURRENCY'], $_SERVER['TVDB_CONCURRENCY']);

    $config = require base_path('config/services.php');

    expect($config['tvdb']['concurrency'] ?? null)->toBe(10);
});

it('binds services.tvdb.key from TVDB_KEY', function (): void {
    $config = require base_path('config/services.php');

    expect($config['tvdb']['key'])->toBe(env('TVDB_KEY'));
});

it('exposes no static token or jwt key', function (): void {
    $config = require base_path('config/services.php');

    $keys = array_keys($config['tvdb'] ?? []);

    expect($keys)->toBe(['key', 'concurrency']);
});

/*
|--------------------------------------------------------------------------
| JWT login, cache, and bearer — series() auth slice
|--------------------------------------------------------------------------
| The first series() call exchanges the configured apikey for a JWT via
| POST /login {apikey}, sends that JWT as the Bearer on the resource GET,
| caches it under the stable key tvdb.jwt, and reuses the cached JWT on a
| later call instead of logging in again.
|
| Fixtures (byte-exact real captures):
|   tests/Fixtures/Catalog/tvdb/login.json — data.token = test.jwt.token
|   tests/Fixtures/Catalog/tvdb/series_extended.json — series 81189 detail
|
| beforeEach flushes the array cache (test-env CACHE_STORE=array persists
| within a run, so a cached JWT would bleed between tests and make the
| "one login" assertion see zero requests) and pins the apikey so /login
| carries a known value.
*/

describe('series() JWT auth', function (): void {
    beforeEach(function (): void {
        Cache::flush();
        config(['services.tvdb.key' => 'test-key']);
    });

    it('POSTs the configured apikey to /login', function (): void {
        Http::fake([
            '*api4.thetvdb.com/v4/login*' => Http::response(fixtureBytes('Catalog/tvdb/login.json')),
            '*api4.thetvdb.com/v4/series/*' => Http::response(fixtureBytes('Catalog/tvdb/series_extended.json')),
        ]);

        resolve(TvdbApiService::class)->series(81189);

        Http::assertSent(fn ($request): bool => str_contains((string) $request->url(), '/login')
            && $request->method() === 'POST'
            && data_get($request->data(), 'apikey') === 'test-key');
    });

    it('sends the resource GET with the login JWT as Bearer', function (): void {
        Http::fake([
            '*api4.thetvdb.com/v4/login*' => Http::response(fixtureBytes('Catalog/tvdb/login.json')),
            '*api4.thetvdb.com/v4/series/*' => Http::response(fixtureBytes('Catalog/tvdb/series_extended.json')),
        ]);

        resolve(TvdbApiService::class)->series(81189);

        Http::assertSent(fn ($request): bool => str_contains((string) $request->url(), '/series/81189')
            && $request->hasHeader('Authorization', 'Bearer test.jwt.token'));
    });

    it('caches the JWT under tvdb.jwt', function (): void {
        Http::fake([
            '*api4.thetvdb.com/v4/login*' => Http::response(fixtureBytes('Catalog/tvdb/login.json')),
            '*api4.thetvdb.com/v4/series/*' => Http::response(fixtureBytes('Catalog/tvdb/series_extended.json')),
        ]);

        resolve(TvdbApiService::class)->series(81189);

        expect(Cache::get('tvdb.jwt'))->toBe('test.jwt.token');
    });

    it('reuses the cached JWT, logging in exactly once across two series() calls', function (): void {
        Http::fake([
            '*api4.thetvdb.com/v4/login*' => Http::response(fixtureBytes('Catalog/tvdb/login.json')),
            '*api4.thetvdb.com/v4/series/*' => Http::response(fixtureBytes('Catalog/tvdb/series_extended.json')),
        ]);

        resolve(TvdbApiService::class)->series(81189);
        resolve(TvdbApiService::class)->series(81189);

        expect(Http::recorded(fn ($request): bool => str_contains((string) $request->url(), '/login')))->toHaveCount(1);
    });

    it('re-logins once and returns the payload on a 401-then-200 sequence', function (): void {
        Http::fake([
            '*api4.thetvdb.com/v4/login*' => Http::response(fixtureBytes('Catalog/tvdb/login.json')),
            '*api4.thetvdb.com/v4/series/*' => Http::sequence()
                ->push('', 401)
                ->push(fixtureBytes('Catalog/tvdb/series_extended.json'), 200),
        ]);

        $payload = resolve(TvdbApiService::class)->series(81189);

        expect($payload)->toBe(json_decode(fixtureBytes('Catalog/tvdb/series_extended.json'), true));
    });

    it('fires a second /login after the 401', function (): void {
        Http::fake([
            '*api4.thetvdb.com/v4/login*' => Http::response(fixtureBytes('Catalog/tvdb/login.json')),
            '*api4.thetvdb.com/v4/series/*' => Http::sequence()
                ->push('', 401)
                ->push(fixtureBytes('Catalog/tvdb/series_extended.json'), 200),
        ]);

        resolve(TvdbApiService::class)->series(81189);

        expect(Http::recorded(fn ($request): bool => str_contains((string) $request->url(), '/login')))->toHaveCount(2);
    });

    it('throws TvdbAuthenticationFailed when the 401 persists', function (): void {
        Http::fake([
            '*api4.thetvdb.com/v4/login*' => Http::response(fixtureBytes('Catalog/tvdb/login.json')),
            '*api4.thetvdb.com/v4/series/*' => Http::response('', 401),
        ]);

        expect(fn () => resolve(TvdbApiService::class)->series(81189))->toThrow(TvdbAuthenticationFailed::class);
    });

    it('re-logins at most once on a persistent 401 (no loop)', function (): void {
        Http::fake([
            '*api4.thetvdb.com/v4/login*' => Http::response(fixtureBytes('Catalog/tvdb/login.json')),
            '*api4.thetvdb.com/v4/series/*' => Http::response('', 401),
        ]);

        try {
            resolve(TvdbApiService::class)->series(81189);
        } catch (TvdbAuthenticationFailed) {
            // swallow: the Act is making the failing call; the assertion is the login count
        }

        expect(Http::recorded(fn ($request): bool => str_contains((string) $request->url(), '/login')))->toHaveCount(2);
    });

    it('throws TvdbAuthenticationFailed and caches nothing when /login returns no usable token', function (): void {
        Http::fake([
            '*api4.thetvdb.com/v4/login*' => Http::response(['status' => 'success', 'data' => ['token' => '']]),
            '*api4.thetvdb.com/v4/series/*' => Http::response(fixtureBytes('Catalog/tvdb/series_extended.json')),
        ]);

        expect(fn () => resolve(TvdbApiService::class)->series(81189))->toThrow(TvdbAuthenticationFailed::class);

        expect(Cache::get('tvdb.jwt'))->toBeNull();
    });

    it('re-attempts login rather than presenting a null bearer after a malformed login body', function (): void {
        Http::fake([
            '*api4.thetvdb.com/v4/login*' => Http::sequence()
                ->push(['status' => 'success', 'data' => []])
                ->push(fixtureBytes('Catalog/tvdb/login.json')),
            '*api4.thetvdb.com/v4/series/*' => Http::response(fixtureBytes('Catalog/tvdb/series_extended.json')),
        ]);

        try {
            resolve(TvdbApiService::class)->series(81189);
        } catch (TvdbAuthenticationFailed) {
            // first call: malformed login body must not cache a null bearer
        }
        $payload = resolve(TvdbApiService::class)->series(81189);

        expect($payload)->toBe(json_decode(fixtureBytes('Catalog/tvdb/series_extended.json'), true));
    });
});

/*
|--------------------------------------------------------------------------
| decode() / get() status mapping — typed failures
|--------------------------------------------------------------------------
| series() funnels its resource response through decode()/get(): a 404 is a
| miss (null), but every other failure is a typed TvdbRequestFailed — an
| undecodable 200 body, a non-404 error status, and a transport-level
| ConnectionException surviving retries (normalized, never raw). The 401 path
| belongs to the re-login slice above and is not retested here.
*/

describe('decode() / get() status mapping', function (): void {
    beforeEach(function (): void {
        Cache::flush();
        config(['services.tvdb.key' => 'test-key']);
    });

    it('returns null when the resource 404s', function (): void {
        Http::fake([
            '*api4.thetvdb.com/v4/login*' => Http::response(fixtureBytes('Catalog/tvdb/login.json')),
            '*api4.thetvdb.com/v4/series/*' => Http::response('', 404),
        ]);

        $payload = resolve(TvdbApiService::class)->series(81189);

        expect($payload)->toBeNull();
    });

    it('throws TvdbRequestFailed on a 200 whose body decodes to null', function (): void {
        Http::fake([
            '*api4.thetvdb.com/v4/login*' => Http::response(fixtureBytes('Catalog/tvdb/login.json')),
            '*api4.thetvdb.com/v4/series/*' => Http::response('', 200),
        ]);

        expect(fn () => resolve(TvdbApiService::class)->series(81189))->toThrow(TvdbRequestFailed::class);
    });

    it('throws TvdbRequestFailed on a non-404 error status', function (): void {
        Http::fake([
            '*api4.thetvdb.com/v4/login*' => Http::response(fixtureBytes('Catalog/tvdb/login.json')),
            '*api4.thetvdb.com/v4/series/*' => Http::response('', 400),
        ]);

        expect(fn () => resolve(TvdbApiService::class)->series(81189))->toThrow(TvdbRequestFailed::class);
    });

    it('throws TvdbRequestFailed, not a raw ConnectionException, when the resource fails at the transport level past retries', function (): void {
        Sleep::fake();
        Http::fake([
            '*api4.thetvdb.com/v4/login*' => Http::response(fixtureBytes('Catalog/tvdb/login.json')),
            '*api4.thetvdb.com/v4/series/*' => fn () => throw new ConnectionException('Connection timed out'),
        ]);

        expect(fn () => resolve(TvdbApiService::class)->series(81189))->toThrow(TvdbRequestFailed::class);
    });
});

/*
|--------------------------------------------------------------------------
| retry policy & backoff — series() resilience
|--------------------------------------------------------------------------
| Transient resource failures (429 / 5xx / connection) are retried up to 2
| attempts before giving up; a 404 is a definitive miss and is never retried.
| When a 429 carries a Retry-After header (seconds), that drives the backoff
| wait (converted to ms); otherwise a 1000ms base delay applies. Every fake
| map answers BOTH /login and /series (Http::preventStrayRequests() is global),
| and Sleep is faked so the base 1000ms retry delay doesn't really sleep.
*/

describe('retry policy & backoff', function (): void {
    beforeEach(function (): void {
        Cache::flush();
        config(['services.tvdb.key' => 'test-key']);
    });

    it('retries a transient 500 and returns the payload from the retry', function (): void {
        Sleep::fake();
        Http::fake([
            '*api4.thetvdb.com/v4/login*' => Http::response(fixtureBytes('Catalog/tvdb/login.json')),
            '*api4.thetvdb.com/v4/series/*' => Http::sequence()
                ->push('', 500)
                ->push(fixtureBytes('Catalog/tvdb/series_extended.json'), 200),
        ]);

        $payload = resolve(TvdbApiService::class)->series(81189);

        expect($payload)->toBe(json_decode(fixtureBytes('Catalog/tvdb/series_extended.json'), true))
            ->and(Http::recorded(fn ($request): bool => str_contains((string) $request->url(), '/series')))->toHaveCount(2);
    });

    it('throws TvdbRequestFailed when a 500 persists past retries', function (): void {
        Sleep::fake();
        Http::fake([
            '*api4.thetvdb.com/v4/login*' => Http::response(fixtureBytes('Catalog/tvdb/login.json')),
            '*api4.thetvdb.com/v4/series/*' => Http::response('', 500),
        ]);

        expect(fn () => resolve(TvdbApiService::class)->series(81189))->toThrow(TvdbRequestFailed::class);
    });

    it('does not retry a 404', function (): void {
        Sleep::fake();
        Http::fake([
            '*api4.thetvdb.com/v4/login*' => Http::response(fixtureBytes('Catalog/tvdb/login.json')),
            '*api4.thetvdb.com/v4/series/*' => Http::response('', 404),
        ]);

        resolve(TvdbApiService::class)->series(81189);

        expect(Http::recorded(fn ($request): bool => str_contains((string) $request->url(), '/series')))->toHaveCount(1);
    });
});

/*
|--------------------------------------------------------------------------
| series() / episode() single fetch — extended endpoints
|--------------------------------------------------------------------------
| Each single-resource fetch GETs its `…/extended` endpoint with the cached
| JWT as Bearer and returns the raw decoded payload unchanged, mapping a 404
| to null. Fixtures are byte-exact captures: series_extended.json (id 81189)
| and episode_extended.json (id 3859781). Every fake map answers BOTH /login
| and the resource path, since Http::preventStrayRequests() is global.
*/

describe('series() / episode() single fetch', function (): void {
    beforeEach(function (): void {
        Cache::flush();
        config(['services.tvdb.key' => 'test-key']);
    });

    it('sends a Bearer-authed GET to /series/{id}/extended', function (): void {
        Http::fake([
            '*api4.thetvdb.com/v4/login*' => Http::response(fixtureBytes('Catalog/tvdb/login.json')),
            '*api4.thetvdb.com/v4/series/*' => Http::response(fixtureBytes('Catalog/tvdb/series_extended.json')),
        ]);

        resolve(TvdbApiService::class)->series(81189);

        Http::assertSent(fn ($request): bool => str_contains((string) $request->url(), '/series/81189/extended')
            && $request->hasHeader('Authorization', 'Bearer test.jwt.token'));
    });

    it('returns the raw series payload unchanged', function (): void {
        Http::fake([
            '*api4.thetvdb.com/v4/login*' => Http::response(fixtureBytes('Catalog/tvdb/login.json')),
            '*api4.thetvdb.com/v4/series/*' => Http::response(fixtureBytes('Catalog/tvdb/series_extended.json')),
        ]);

        $result = resolve(TvdbApiService::class)->series(81189);

        expect($result)->toBe(json_decode(fixtureBytes('Catalog/tvdb/series_extended.json'), true));
    });

    it('GETs /episodes/{id}/extended and returns the raw payload', function (): void {
        Http::fake([
            '*api4.thetvdb.com/v4/login*' => Http::response(fixtureBytes('Catalog/tvdb/login.json')),
            '*api4.thetvdb.com/v4/episodes/*' => Http::response(fixtureBytes('Catalog/tvdb/episode_extended.json')),
        ]);

        $result = resolve(TvdbApiService::class)->episode(3859781);

        Http::assertSent(fn ($request): bool => str_contains((string) $request->url(), '/episodes/3859781/extended')
            && $request->hasHeader('Authorization', 'Bearer test.jwt.token'));
        expect($result)->toBe(json_decode(fixtureBytes('Catalog/tvdb/episode_extended.json'), true));
    });

    it('returns null when the episode 404s', function (): void {
        Http::fake([
            '*api4.thetvdb.com/v4/login*' => Http::response(fixtureBytes('Catalog/tvdb/login.json')),
            '*api4.thetvdb.com/v4/episodes/*' => Http::response('', 404),
        ]);

        $result = resolve(TvdbApiService::class)->episode(3859781);

        expect($result)->toBeNull();
    });
});
