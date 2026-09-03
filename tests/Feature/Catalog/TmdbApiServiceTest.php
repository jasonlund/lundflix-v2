<?php

declare(strict_types=1);

use App\Domains\Catalog\Exceptions\TmdbAuthenticationFailed;
use App\Domains\Catalog\Exceptions\TmdbRequestFailed;
use App\Domains\Catalog\Services\TmdbApiService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Fixture: tests/Fixtures/Catalog/tmdb/movie.json
|--------------------------------------------------------------------------
| Byte-exact live capture of the TMDB movie detail endpoint for id 603
| (The Matrix), in the API's native JSON wire format. Loaded into Http::fake()
| as the response body; never hand-fabricated.
*/

describe('movie() detail fetch', function (): void {
    it('sends a Bearer-authed GET to /movie/{id}', function (): void {
        config(['services.tmdb.token' => 'test-token']);
        Http::fake(['*api.themoviedb.org*' => Http::response(fixtureBytes('Catalog/tmdb/movie.json'))]);

        resolve(TmdbApiService::class)->movie(603);

        Http::assertSent(fn ($request): bool => Str::contains((string) $request->url(), '/movie/603')
            && $request->hasHeader('Authorization', 'Bearer test-token'));
    });

    it('appends the movie sub-resources and image-language params', function (): void {
        config(['services.tmdb.token' => 'test-token']);
        Http::fake(['*api.themoviedb.org*' => Http::response(fixtureBytes('Catalog/tmdb/movie.json'))]);

        resolve(TmdbApiService::class)->movie(603);

        Http::assertSent(fn ($request): bool => Str::contains(urldecode((string) $request->url()), 'append_to_response=release_dates,images')
            && Str::contains(urldecode((string) $request->url()), 'include_image_language=en,null'));
    });

    it('returns the raw payload unchanged', function (): void {
        config(['services.tmdb.token' => 'test-token']);
        Http::fake(['*api.themoviedb.org*' => Http::response(fixtureBytes('Catalog/tmdb/movie.json'))]);

        $result = resolve(TmdbApiService::class)->movie(603);

        expect($result)->toBe(json_decode(fixtureBytes('Catalog/tmdb/movie.json'), true));
    });

    it('returns null on 404', function (): void {
        config(['services.tmdb.token' => 'test-token']);
        Http::fake(['*api.themoviedb.org*' => Http::response('', 404)]);

        $result = resolve(TmdbApiService::class)->movie(999999);

        expect($result)->toBeNull();
    });

    it('throws TmdbRequestFailed on a successful 200 whose body decodes to null', function (): void {
        config(['services.tmdb.token' => 'test-token']);
        Http::fake(['*api.themoviedb.org*' => Http::response('', 200)]);

        $call = fn () => resolve(TmdbApiService::class)->movie(603);

        expect($call)->toThrow(TmdbRequestFailed::class);
    });
});

/*
|--------------------------------------------------------------------------
| movies(array $ids): pooled batch fetch of /movie/{id} details
|--------------------------------------------------------------------------
| Fires one request per id via Http::pool() and returns [id => array|null]
| keyed by the input tmdb id. Http::fake() matches by URL (not pool key), so
| each id gets a distinct url pattern in the fake map, all reusing the
| byte-exact movie.json (id 603) fixture body. A per-id 404 yields null for
| that id without sinking the others.
*/

describe('movies() pooled batch fetch', function (): void {
    it('returns an array keyed by the input tmdb ids', function (): void {
        config(['services.tmdb.token' => 'test-token']);
        Http::fake([
            '*/movie/603*' => Http::response(fixtureBytes('Catalog/tmdb/movie.json')),
            '*/movie/604*' => Http::response(fixtureBytes('Catalog/tmdb/movie.json')),
        ]);

        $result = resolve(TmdbApiService::class)->movies([603, 604]);

        expect(array_keys($result))->toBe([603, 604]);
    });

    it('values each id to its raw payload', function (): void {
        config(['services.tmdb.token' => 'test-token']);
        Http::fake([
            '*/movie/603*' => Http::response(fixtureBytes('Catalog/tmdb/movie.json')),
            '*/movie/604*' => Http::response(fixtureBytes('Catalog/tmdb/movie.json')),
        ]);

        $result = resolve(TmdbApiService::class)->movies([603, 604]);

        expect($result[603])->toBe(json_decode(fixtureBytes('Catalog/tmdb/movie.json'), true));
    });

    it('yields null for a 404 id while others still resolve', function (): void {
        config(['services.tmdb.token' => 'test-token']);
        Http::fake([
            '*/movie/603*' => Http::response(fixtureBytes('Catalog/tmdb/movie.json')),
            '*/movie/999*' => Http::response('', 404),
        ]);

        $result = resolve(TmdbApiService::class)->movies([603, 999]);

        expect($result[603])->toBe(json_decode(fixtureBytes('Catalog/tmdb/movie.json'), true))
            ->and($result[999])->toBeNull();
    });

    it('fires one request per id', function (): void {
        config(['services.tmdb.token' => 'test-token']);
        Http::fake([
            '*/movie/603*' => Http::response(fixtureBytes('Catalog/tmdb/movie.json')),
            '*/movie/604*' => Http::response(fixtureBytes('Catalog/tmdb/movie.json')),
        ]);

        resolve(TmdbApiService::class)->movies([603, 604]);

        Http::assertSentCount(2);
    });

    it('de-duplicates repeated input ids, firing one request per unique id and keying the result in first-seen order', function (): void {
        config(['services.tmdb.token' => 'test-token']);
        Http::fake([
            '*/movie/603*' => Http::response(fixtureBytes('Catalog/tmdb/movie.json')),
            '*/movie/604*' => Http::response(fixtureBytes('Catalog/tmdb/movie.json')),
        ]);

        $result = resolve(TmdbApiService::class)->movies([603, 603, 604]);

        Http::assertSentCount(2);
        expect(array_keys($result))->toBe([603, 604]);
    });
});

/*
|--------------------------------------------------------------------------
| movies(array $ids): a transport failure inside the pool reports, not throws
|--------------------------------------------------------------------------
| When one id's request fails at the connection/transport level and exhausts
| retries, Http::pool() places a ConnectionException object at that slot
| (it does not throw). The batch must surface that as a domain
| TmdbRequestFailed via report() — not a raw TypeError from passing the
| exception to a Response-typed decoder — while still RETURNING the succeeding
| ids so one transient failure doesn't drop the whole batch's good rows. Every
| id is evaluated, so a multi-id failure reports ALL failed ids together.
| shouldRetry() retries a non-RequestException, so the connection failure
| exhausts retries (Sleep is faked so the base delay doesn't sleep), and the
| fake throws a ConnectionException for the failing id's url. Exceptions::fake()
| captures the reported aggregate.
*/

describe('movies() transport failures', function (): void {
    it('reports TmdbRequestFailed and still returns the succeeding id when one id in the batch fails at the transport level', function (): void {
        // Arrange
        config(['services.tmdb.token' => 'test-token']);
        Sleep::fake();
        Exceptions::fake();
        Http::fake([
            '*/movie/603*' => fn () => throw new ConnectionException('Connection timed out'),
            '*/movie/604*' => Http::response(fixtureBytes('Catalog/tmdb/movie.json')),
        ]);

        // Act
        $result = resolve(TmdbApiService::class)->movies([603, 604]);

        // Assert
        expect($result)->toBe([604 => json_decode(fixtureBytes('Catalog/tmdb/movie.json'), true)]);
        Exceptions::assertReported(fn (TmdbRequestFailed $e): bool => Str::contains($e->getMessage(), '603'));
    });

    it('reports every failed id and still returns the succeeding id when multiple ids in the batch fail at the transport level', function (): void {
        // Arrange
        config(['services.tmdb.token' => 'test-token']);
        Sleep::fake();
        Exceptions::fake();
        Http::fake([
            '*/movie/603*' => fn () => throw new ConnectionException('Connection timed out'),
            '*/movie/604*' => Http::response(fixtureBytes('Catalog/tmdb/movie.json')),
            '*/movie/605*' => fn () => throw new ConnectionException('Connection timed out'),
        ]);

        // Act
        $result = resolve(TmdbApiService::class)->movies([603, 604, 605]);

        // Assert
        expect($result)->toBe([604 => json_decode(fixtureBytes('Catalog/tmdb/movie.json'), true)]);
        Exceptions::assertReported(
            fn (TmdbRequestFailed $e): bool => Str::contains($e->getMessage(), '603') && Str::contains($e->getMessage(), '605')
        );
    });

    it('reports every failed id and still returns the succeeding id when multiple ids in the batch keep returning a 500 past retries', function (): void {
        // Arrange
        config(['services.tmdb.token' => 'test-token']);
        Sleep::fake();
        Exceptions::fake();
        Http::fake([
            '*/movie/603*' => Http::response('', 500),
            '*/movie/604*' => Http::response(fixtureBytes('Catalog/tmdb/movie.json')),
            '*/movie/605*' => Http::response('', 500),
        ]);

        // Act
        $result = resolve(TmdbApiService::class)->movies([603, 604, 605]);

        // Assert
        expect($result)->toBe([604 => json_decode(fixtureBytes('Catalog/tmdb/movie.json'), true)]);
        Exceptions::assertReported(
            fn (TmdbRequestFailed $e): bool => Str::contains($e->getMessage(), '603') && Str::contains($e->getMessage(), '605')
        );
    });
});

/*
|--------------------------------------------------------------------------
| movies(array $ids): auth and undecodable failures inside the pool
|--------------------------------------------------------------------------
| resolvePooled collects a persistent non-404, non-401 failure AND an
| undecodable 200 as a per-id PooledIdFailed, so both aggregate into the single
| reported TmdbRequestFailed::forIds and never sink the batch's good rows. Only
| a 401 stays fatal for the whole batch: it falls through to decode(), which
| throws TmdbAuthenticationFailed immediately. So a single id's 401 inside the
| batch surfaces as a fatal TmdbAuthenticationFailed, while a single id's
| undecodable 200 is aggregated with any other failed ids and the succeeding
| ids are still returned. The 401/empty-200 error bodies real data can't
| produce are synthetic; the succeeding id reuses the byte-exact movie.json
| fixture.
*/

describe('movies() auth and undecodable failures', function (): void {
    it('throws TmdbAuthenticationFailed when one id in the batch returns a 401', function (): void {
        config(['services.tmdb.token' => 'test-token']);
        Http::fake([
            '*/movie/603*' => Http::response(fixtureBytes('Catalog/tmdb/movie.json')),
            '*/movie/604*' => Http::response('', 401),
        ]);

        $call = fn () => resolve(TmdbApiService::class)->movies([603, 604]);

        expect($call)->toThrow(TmdbAuthenticationFailed::class);
    });

    it('reports the undecodable-200 id and still returns the succeeding prior id rather than discarding the batch', function (): void {
        // Arrange
        config(['services.tmdb.token' => 'test-token']);
        Exceptions::fake();
        Http::fake([
            '*/movie/603*' => Http::response(fixtureBytes('Catalog/tmdb/movie.json')),
            '*/movie/604*' => Http::response('not json', 200),
        ]);

        // Act
        $result = resolve(TmdbApiService::class)->movies([603, 604]);

        // Assert
        expect($result)->toBe([603 => json_decode(fixtureBytes('Catalog/tmdb/movie.json'), true)]);
        Exceptions::assertReported(fn (TmdbRequestFailed $e): bool => Str::contains($e->getMessage(), '604'));
    });

    it('reports a 5xx id and an undecodable-200 id together in one aggregate failure and still returns the succeeding id', function (): void {
        // Arrange
        config(['services.tmdb.token' => 'test-token']);
        Sleep::fake();
        Exceptions::fake();
        Http::fake([
            '*/movie/603*' => Http::response(fixtureBytes('Catalog/tmdb/movie.json')),
            '*/movie/604*' => Http::response('', 500),
            '*/movie/605*' => Http::response('not json', 200),
        ]);

        // Act
        $result = resolve(TmdbApiService::class)->movies([603, 604, 605]);

        // Assert
        expect($result)->toBe([603 => json_decode(fixtureBytes('Catalog/tmdb/movie.json'), true)]);
        Exceptions::assertReported(
            fn (TmdbRequestFailed $e): bool => Str::contains($e->getMessage(), '604') && Str::contains($e->getMessage(), '605')
        );
    });
});

/*
|--------------------------------------------------------------------------
| movies(array $ids): chunked pooling sized by services.tmdb.concurrency
|--------------------------------------------------------------------------
| The batch fans out at most `concurrency` concurrent requests at a time by
| splitting the input ids into ordered chunks of that size, dispatching one
| Http::pool() per chunk. This is asserted through the public movies() method
| (not the private chunkIds()): with concurrency=3 and 7 ids, exactly 7
| requests fire, every id is requested, and input order is preserved across
| chunks with the final partial chunk holding the remainder. All ids reuse the
| byte-exact movie.json fixture body, matched per-id by url.
*/

describe('movies() concurrency chunking', function (): void {
    it('fires one request per id when the batch spans multiple concurrency-sized chunks', function (): void {
        config(['services.tmdb.token' => 'test-token', 'services.tmdb.concurrency' => 3]);
        Http::fake(['*/movie/*' => Http::response(fixtureBytes('Catalog/tmdb/movie.json'))]);

        resolve(TmdbApiService::class)->movies([1, 2, 3, 4, 5, 6, 7]);

        Http::assertSentCount(7);
    });

    it('requests every id in input order across the concurrency-sized chunks', function (): void {
        config(['services.tmdb.token' => 'test-token', 'services.tmdb.concurrency' => 3]);
        Http::fake(['*/movie/*' => Http::response(fixtureBytes('Catalog/tmdb/movie.json'))]);

        resolve(TmdbApiService::class)->movies([1, 2, 3, 4, 5, 6, 7]);

        Http::assertSentInOrder([
            fn ($request): bool => Str::contains((string) $request->url(), '/movie/1?'),
            fn ($request): bool => Str::contains((string) $request->url(), '/movie/2?'),
            fn ($request): bool => Str::contains((string) $request->url(), '/movie/3?'),
            fn ($request): bool => Str::contains((string) $request->url(), '/movie/4?'),
            fn ($request): bool => Str::contains((string) $request->url(), '/movie/5?'),
            fn ($request): bool => Str::contains((string) $request->url(), '/movie/6?'),
            fn ($request): bool => Str::contains((string) $request->url(), '/movie/7?'),
        ]);
    });

    it('keys the result by every input id when the batch spills into a partial final chunk', function (): void {
        config(['services.tmdb.token' => 'test-token', 'services.tmdb.concurrency' => 3]);
        Http::fake(['*/movie/*' => Http::response(fixtureBytes('Catalog/tmdb/movie.json'))]);

        $result = resolve(TmdbApiService::class)->movies([1, 2, 3, 4, 5, 6, 7]);

        expect(array_keys($result))->toBe([1, 2, 3, 4, 5, 6, 7]);
    });
});

/*
|--------------------------------------------------------------------------
| Fixture: tests/Fixtures/Catalog/tmdb/tv.json
|--------------------------------------------------------------------------
| Byte-exact live capture of the TMDB TV detail endpoint for id 1399
| (Game of Thrones), in the API's native JSON wire format. Loaded into
| Http::fake() as the response body; never hand-fabricated.
*/

describe('tv() detail fetch', function (): void {
    it('sends a Bearer-authed GET to /tv/{id}', function (): void {
        config(['services.tmdb.token' => 'test-token']);
        Http::fake(['*api.themoviedb.org*' => Http::response(fixtureBytes('Catalog/tmdb/tv.json'))]);

        resolve(TmdbApiService::class)->tv(1399);

        Http::assertSent(fn ($request): bool => Str::contains((string) $request->url(), '/tv/1399')
            && $request->hasHeader('Authorization', 'Bearer test-token'));
    });

    it('appends the tv sub-resources and image-language params', function (): void {
        config(['services.tmdb.token' => 'test-token']);
        Http::fake(['*api.themoviedb.org*' => Http::response(fixtureBytes('Catalog/tmdb/tv.json'))]);

        resolve(TmdbApiService::class)->tv(1399);

        Http::assertSent(fn ($request): bool => Str::contains(urldecode((string) $request->url()), 'append_to_response=images,external_ids,content_ratings')
            && Str::contains(urldecode((string) $request->url()), 'include_image_language=en,null'));
    });

    it('returns the raw tv payload unchanged', function (): void {
        config(['services.tmdb.token' => 'test-token']);
        Http::fake(['*api.themoviedb.org*' => Http::response(fixtureBytes('Catalog/tmdb/tv.json'))]);

        $result = resolve(TmdbApiService::class)->tv(1399);

        expect($result)->toBe(json_decode(fixtureBytes('Catalog/tmdb/tv.json'), true));
    });

    it('returns null on 404 for tv', function (): void {
        config(['services.tmdb.token' => 'test-token']);
        Http::fake(['*api.themoviedb.org*' => Http::response('', 404)]);

        $result = resolve(TmdbApiService::class)->tv(999999);

        expect($result)->toBeNull();
    });
});

describe('shared request retries and failure mapping', function (): void {
    it('retries a transient 500 and returns the payload from the retry', function (): void {
        config(['services.tmdb.token' => 'test-token']);
        Sleep::fake();
        Http::fake(['*api.themoviedb.org*' => Http::sequence()
            ->push('', 500)
            ->push(fixtureBytes('Catalog/tmdb/movie.json'), 200)]);

        $result = resolve(TmdbApiService::class)->movie(603);

        Http::assertSentCount(2);
        expect($result)->toBe(json_decode(fixtureBytes('Catalog/tmdb/movie.json'), true));
    });

    it('throws TmdbRequestFailed when a 500 persists past retries', function (): void {
        config(['services.tmdb.token' => 'test-token']);
        Sleep::fake();
        Http::fake(['*api.themoviedb.org*' => Http::response('', 500)]);

        expect(fn () => resolve(TmdbApiService::class)->movie(603))->toThrow(TmdbRequestFailed::class);
    });

    it('throws TmdbRequestFailed, not a raw ConnectionException, when a single request fails at the transport level past retries', function (): void {
        config(['services.tmdb.token' => 'test-token']);
        Sleep::fake();
        Http::fake(['*api.themoviedb.org*' => fn () => throw new ConnectionException('Connection timed out')]);

        expect(fn () => resolve(TmdbApiService::class)->movie(603))->toThrow(TmdbRequestFailed::class);
    });

    it('throws TmdbAuthenticationFailed on a 401 response', function (): void {
        config(['services.tmdb.token' => 'test-token']);
        Http::fake(['*api.themoviedb.org*' => Http::response('', 401)]);

        expect(fn () => resolve(TmdbApiService::class)->movie(603))->toThrow(TmdbAuthenticationFailed::class);
    });
});

/*
|--------------------------------------------------------------------------
| Fixture: tests/Fixtures/Catalog/tmdb/find_by_imdb.json
|--------------------------------------------------------------------------
| Hand-authored fixture approximating the TMDB /find endpoint response shape
| for the IMDb id tt0133093 (The Matrix), with external_source=imdb_id, in the
| API's JSON wire format. movie_results[0].id=603; all *_results keys present.
| Representative fixture, not a verbatim live capture. Loaded into Http::fake()
| as the response body.
*/

describe('findByImdbId() lookup', function (): void {
    it('sends a Bearer-authed GET to /find/{imdbId} with external_source=imdb_id', function (): void {
        config(['services.tmdb.token' => 'test-token']);
        Http::fake(['*api.themoviedb.org*' => Http::response(fixtureBytes('Catalog/tmdb/find_by_imdb.json'))]);

        resolve(TmdbApiService::class)->findByImdbId('tt0133093');

        Http::assertSent(fn ($request): bool => Str::contains((string) $request->url(), '/find/tt0133093')
            && Str::contains(urldecode((string) $request->url()), 'external_source=imdb_id')
            && $request->hasHeader('Authorization', 'Bearer test-token'));
    });

    it('returns the whole /find payload unchanged', function (): void {
        config(['services.tmdb.token' => 'test-token']);
        Http::fake(['*api.themoviedb.org*' => Http::response(fixtureBytes('Catalog/tmdb/find_by_imdb.json'))]);

        $result = resolve(TmdbApiService::class)->findByImdbId('tt0133093');

        expect($result)->toBe(json_decode(fixtureBytes('Catalog/tmdb/find_by_imdb.json'), true))
            ->and($result)->toHaveKeys(['movie_results', 'tv_results']);
    });

    it('returns null on 404 for findByImdbId', function (): void {
        config(['services.tmdb.token' => 'test-token']);
        Http::fake(['*api.themoviedb.org*' => Http::response('', 404)]);

        $result = resolve(TmdbApiService::class)->findByImdbId('tt9999999');

        expect($result)->toBeNull();
    });
});

/*
|--------------------------------------------------------------------------
| tvShows(array $ids): pooled batch fetch of /tv/{id} details
|--------------------------------------------------------------------------
| Fires one request per id via Http::pool() and returns [tmdbId => array|null]
| keyed by the input tmdb id. Http::fake() matches by URL (not pool key), so
| each id gets a distinct url pattern in the fake map, all reusing the
| byte-exact tv.json (id 1399) fixture body. A per-id 404 yields null for that
| id without sinking the others.
*/

describe('tvShows() pooled batch fetch', function (): void {
    it('returns a tv map keyed by the input tmdb ids hitting /tv/{id}', function (): void {
        config(['services.tmdb.token' => 'test-token']);
        Http::fake([
            '*/tv/1399*' => Http::response(fixtureBytes('Catalog/tmdb/tv.json')),
            '*/tv/1400*' => Http::response(fixtureBytes('Catalog/tmdb/tv.json')),
        ]);

        $result = resolve(TmdbApiService::class)->tvShows([1399, 1400]);

        expect(array_keys($result))->toBe([1399, 1400])
            ->and($result[1399])->toBe(json_decode(fixtureBytes('Catalog/tmdb/tv.json'), true));
    });

    it('yields null for a 404 tv id while others still resolve', function (): void {
        config(['services.tmdb.token' => 'test-token']);
        Http::fake([
            '*/tv/1399*' => Http::response(fixtureBytes('Catalog/tmdb/tv.json')),
            '*/tv/999*' => Http::response('', 404),
        ]);

        $result = resolve(TmdbApiService::class)->tvShows([1399, 999]);

        expect($result[1399])->toBe(json_decode(fixtureBytes('Catalog/tmdb/tv.json'), true))
            ->and($result[999])->toBeNull();
    });
});

/*
|--------------------------------------------------------------------------
| findManyByImdbId(array $imdbIds): pooled batch /find/{id} lookups
|--------------------------------------------------------------------------
| Fires one /find/{imdbId}?external_source=imdb_id request per id via
| Http::pool() and returns [imdbId => array|null] keyed by the input IMDb id.
| Http::fake() matches by URL (not pool key), so each id gets a distinct url
| pattern in the fake map, all reusing the representative find_by_imdb.json
| (tt0133093) fixture body. A per-id 404 yields null for that id without
| sinking the others.
*/

describe('findManyByImdbId() pooled batch lookups', function (): void {
    it('returns a map keyed by the input imdb ids hitting /find/{id} with external_source=imdb_id', function (): void {
        config(['services.tmdb.token' => 'test-token']);
        Http::fake([
            '*/find/tt0133093*' => Http::response(fixtureBytes('Catalog/tmdb/find_by_imdb.json')),
            '*/find/tt0111161*' => Http::response(fixtureBytes('Catalog/tmdb/find_by_imdb.json')),
        ]);

        $result = resolve(TmdbApiService::class)->findManyByImdbId(['tt0133093', 'tt0111161']);

        expect(array_keys($result))->toBe(['tt0133093', 'tt0111161'])
            ->and($result['tt0133093'])->toBe(json_decode(fixtureBytes('Catalog/tmdb/find_by_imdb.json'), true));
        Http::assertSent(fn ($request): bool => Str::contains((string) $request->url(), '/find/tt0133093')
            && Str::contains(urldecode((string) $request->url()), 'external_source=imdb_id'));
    });

    it('yields null for a 404 imdb id while others still resolve', function (): void {
        config(['services.tmdb.token' => 'test-token']);
        Http::fake([
            '*/find/tt0133093*' => Http::response(fixtureBytes('Catalog/tmdb/find_by_imdb.json')),
            '*/find/tt9999999*' => Http::response('', 404),
        ]);

        $result = resolve(TmdbApiService::class)->findManyByImdbId(['tt0133093', 'tt9999999']);

        expect($result['tt0133093'])->toBe(json_decode(fixtureBytes('Catalog/tmdb/find_by_imdb.json'), true))
            ->and($result['tt9999999'])->toBeNull();
    });
});

/*
|--------------------------------------------------------------------------
| Fixture: tests/Fixtures/Catalog/tmdb/configuration.json
|--------------------------------------------------------------------------
| Byte-exact live capture of the TMDB /configuration endpoint, in the API's
| native JSON wire format. Contains the images base urls and size lists.
| Loaded into Http::fake() as the response body; never hand-fabricated.
*/

describe('configuration() fetch', function (): void {
    it('sends a Bearer-authed GET to /configuration', function (): void {
        config(['services.tmdb.token' => 'test-token']);
        Http::fake(['*api.themoviedb.org*' => Http::response(fixtureBytes('Catalog/tmdb/configuration.json'))]);

        resolve(TmdbApiService::class)->configuration();

        Http::assertSent(fn ($request): bool => Str::contains((string) $request->url(), '/configuration')
            && $request->hasHeader('Authorization', 'Bearer test-token'));
    });

    it('returns the raw configuration payload including images', function (): void {
        config(['services.tmdb.token' => 'test-token']);
        Http::fake(['*api.themoviedb.org*' => Http::response(fixtureBytes('Catalog/tmdb/configuration.json'))]);

        $result = resolve(TmdbApiService::class)->configuration();

        expect($result)->toHaveKey('images')
            ->and($result['images']['secure_base_url'])->toBe('https://image.tmdb.org/t/p/')
            ->and($result['images']['poster_sizes'])->not->toBeEmpty();
    });
});

/*
|--------------------------------------------------------------------------
| Fixtures: tests/Fixtures/Catalog/tmdb/movie_changes_page{1,2}.json
|--------------------------------------------------------------------------
| Hand-authored fixtures approximating the TMDB /movie/changes endpoint
| response shape, in the API's JSON wire format, for the two pages of a paged
| change feed. page1 (page:1, total_pages:2) carries results ids 345, 1648226,
| 1713517; page2 (page:2, total_pages:2) carries 1713517, 38702, 1712865 — id
| 1713517 spans both pages so the yielded stream must dedupe to exactly 5 ids.
| Representative fixtures, not verbatim live captures. Loaded into Http::fake()
| as a 2-response sequence to drive pagination.
*/

describe('changedMovieIds() paged change feed', function (): void {
    it('sends a Bearer-authed GET to /movie/changes with start_date/end_date/page params', function (): void {
        config(['services.tmdb.token' => 'test-token']);
        Http::fake(['*api.themoviedb.org*' => Http::sequence()
            ->push(fixtureBytes('Catalog/tmdb/movie_changes_page1.json'))
            ->push(fixtureBytes('Catalog/tmdb/movie_changes_page2.json'))]);

        iterator_to_array(resolve(TmdbApiService::class)->changedMovieIds('2026-06-13', '2026-06-14'), false);

        Http::assertSent(fn ($request): bool => Str::contains((string) $request->url(), '/movie/changes')
            && Str::contains(urldecode((string) $request->url()), 'start_date=2026-06-13')
            && Str::contains(urldecode((string) $request->url()), 'end_date=2026-06-14')
            && Str::contains(urldecode((string) $request->url()), 'page=1')
            && $request->hasHeader('Authorization', 'Bearer test-token'));
    });

    it('follows pagination across total_pages', function (): void {
        config(['services.tmdb.token' => 'test-token']);
        Http::fake(['*api.themoviedb.org*' => Http::sequence()
            ->push(fixtureBytes('Catalog/tmdb/movie_changes_page1.json'))
            ->push(fixtureBytes('Catalog/tmdb/movie_changes_page2.json'))]);

        iterator_to_array(resolve(TmdbApiService::class)->changedMovieIds('2026-06-13', '2026-06-14'), false);

        Http::assertSentCount(2);
        Http::assertSent(fn ($request): bool => Str::contains(urldecode((string) $request->url()), 'page=2'));
    });

    it('sends no request until the generator is first iterated', function (): void {
        config(['services.tmdb.token' => 'test-token']);
        Http::fake(['*api.themoviedb.org*' => Http::sequence()
            ->push(fixtureBytes('Catalog/tmdb/movie_changes_page1.json'))
            ->push(fixtureBytes('Catalog/tmdb/movie_changes_page2.json'))]);

        resolve(TmdbApiService::class)->changedMovieIds('2026-06-13', '2026-06-14');

        // Deliberately never iterated: the returned generator's body must not have
        // run at all, so not even page 1 is fetched.
        Http::assertNothingSent();
    });

    it('fetches page 2 only after page 1\'s ids are consumed', function (): void {
        config(['services.tmdb.token' => 'test-token']);
        Http::fake(['*api.themoviedb.org*' => Http::sequence()
            ->push(fixtureBytes('Catalog/tmdb/movie_changes_page1.json'))
            ->push(fixtureBytes('Catalog/tmdb/movie_changes_page2.json'))]);

        $ids = resolve(TmdbApiService::class)->changedMovieIds('2026-06-13', '2026-06-14');
        foreach ($ids as $id) {
            break;
        }

        // This is the assertion that proves per-page streaming rather than mere
        // deferral: an implementation that materialises every page into an array
        // and then `yield from`s it still sends nothing until first iteration (so
        // it passes the test above), but fetches both pages the moment one id is
        // pulled — only a page-at-a-time generator stops at 1 request here.
        Http::assertSentCount(1);
    });

    it('yields every movie id across pages, deduped, as ints', function (): void {
        config(['services.tmdb.token' => 'test-token']);
        Http::fake(['*api.themoviedb.org*' => Http::sequence()
            ->push(fixtureBytes('Catalog/tmdb/movie_changes_page1.json'))
            ->push(fixtureBytes('Catalog/tmdb/movie_changes_page2.json'))]);

        // The generator skips ids it has already seen, so its keys are not
        // guaranteed contiguous — drain with preserve_keys: false to get the list
        // the array-only expectations below need. (PHP 8.2+ iterator_to_array also
        // accepts a plain array, so the stream contract needs its own assertion.)
        $ids = resolve(TmdbApiService::class)->changedMovieIds('2026-06-13', '2026-06-14');
        $result = iterator_to_array($ids, false);

        expect($ids)->toBeInstanceOf(Generator::class)
            ->and($result)->toContain(345, 1648226, 1713517, 38702, 1712865)
            ->and($result)->each->toBeInt()
            ->and(array_keys($result, 1713517, true))->toHaveCount(1)
            ->and($result)->toHaveCount(5);
    });

    it('yields a result flagged neither adult nor softcore', function (): void {
        config(['services.tmdb.token' => 'test-token']);
        // Synthetic single-page bodies, not the committed page fixtures: every
        // captured row is adult:false/softcore:false, and a single page keeps
        // pagination out of the filter under test.
        Http::fake(['*api.themoviedb.org*' => Http::response(json_encode([
            'results' => [['id' => 345, 'adult' => false, 'softcore' => false]],
            'page' => 1,
            'total_pages' => 1,
            'total_results' => 1,
        ]))]);

        $result = iterator_to_array(resolve(TmdbApiService::class)->changedMovieIds('2026-06-13', '2026-06-14'), false);

        expect($result)->toBe([345]);
    });

    it('keeps a result carrying neither flag', function (): void {
        config(['services.tmdb.token' => 'test-token']);
        Http::fake(['*api.themoviedb.org*' => Http::response(json_encode([
            'results' => [['id' => 345], ['id' => 1648226]],
            'page' => 1,
            'total_pages' => 1,
            'total_results' => 2,
        ]))]);

        $result = iterator_to_array(resolve(TmdbApiService::class)->changedMovieIds('2026-06-13', '2026-06-14'), false);

        // An absent flag is not a set one: a feed row that omits the keys is
        // ordinary, so nothing may be dropped for lacking them.
        expect($result)->toBe([345, 1648226]);
    });

    it('drops a result flagged adult', function (): void {
        config(['services.tmdb.token' => 'test-token']);
        Http::fake(['*api.themoviedb.org*' => Http::response(json_encode([
            'results' => [
                ['id' => 345, 'adult' => false, 'softcore' => false],
                ['id' => 999999, 'adult' => true, 'softcore' => false],
            ],
            'page' => 1,
            'total_pages' => 1,
            'total_results' => 2,
        ]))]);

        $result = iterator_to_array(resolve(TmdbApiService::class)->changedMovieIds('2026-06-13', '2026-06-14'), false);

        expect($result)->not->toContain(999999)
            ->and($result)->toBe([345]);
    });

    it('drops a result flagged softcore', function (): void {
        config(['services.tmdb.token' => 'test-token']);
        Http::fake(['*api.themoviedb.org*' => Http::response(json_encode([
            'results' => [
                ['id' => 345, 'adult' => false, 'softcore' => false],
                ['id' => 999998, 'adult' => false, 'softcore' => true],
            ],
            'page' => 1,
            'total_pages' => 1,
            'total_results' => 2,
        ]))]);

        $result = iterator_to_array(resolve(TmdbApiService::class)->changedMovieIds('2026-06-13', '2026-06-14'), false);

        expect($result)->not->toContain(999998)
            ->and($result)->toBe([345]);
    });

    it('surfaces a 404 page as TmdbRequestFailed on first iteration', function (): void {
        config(['services.tmdb.token' => 'test-token']);
        Http::fake(['*api.themoviedb.org*' => Http::response('', 404)]);

        // The drain sits inside the closure: a lazy generator raises nothing at
        // call time, so the failure can only surface once it is iterated.
        $call = fn (): array => iterator_to_array(resolve(TmdbApiService::class)->changedMovieIds('2026-06-13', '2026-06-14'), false);

        expect($call)->toThrow(TmdbRequestFailed::class);
    });

    it('surfaces a post-retry ConnectionException as TmdbRequestFailed on first iteration', function (): void {
        config(['services.tmdb.token' => 'test-token']);
        Sleep::fake();
        Http::fake(['*api.themoviedb.org*' => fn () => throw new ConnectionException('Connection timed out')]);

        $call = fn (): array => iterator_to_array(resolve(TmdbApiService::class)->changedMovieIds('2026-06-13', '2026-06-14'), false);

        expect($call)->toThrow(TmdbRequestFailed::class);
    });
});

/*
|--------------------------------------------------------------------------
| Fixtures: tests/Fixtures/Catalog/tmdb/tv_changes_page{1,2}.json
|--------------------------------------------------------------------------
| Hand-authored fixtures approximating the TMDB /tv/changes endpoint response
| shape, in the API's JSON wire format, for the two pages of a paged change
| feed. page1 (page:1, total_pages:2) carries results ids 23310, 325296; page2
| (page:2, total_pages:2) carries 325296, 325358, 314402 — id 325296 spans
| both pages so the yielded stream must dedupe to exactly 4 ids. Representative
| fixtures, not verbatim live captures. Loaded into Http::fake() as a
| 2-response sequence to drive pagination.
*/

describe('changedTvIds() paged change feed', function (): void {
    it('GETs /tv/changes with date/page params and follows total_pages', function (): void {
        config(['services.tmdb.token' => 'test-token']);
        Http::fake(['*api.themoviedb.org*' => Http::sequence()
            ->push(fixtureBytes('Catalog/tmdb/tv_changes_page1.json'))
            ->push(fixtureBytes('Catalog/tmdb/tv_changes_page2.json'))]);

        iterator_to_array(resolve(TmdbApiService::class)->changedTvIds('2026-06-13', '2026-06-14'), false);

        Http::assertSent(fn ($request): bool => Str::contains((string) $request->url(), '/tv/changes')
            && Str::contains(urldecode((string) $request->url()), 'start_date=2026-06-13')
            && Str::contains(urldecode((string) $request->url()), 'end_date=2026-06-14')
            && Str::contains(urldecode((string) $request->url()), 'page=1')
            && $request->hasHeader('Authorization', 'Bearer test-token'));
        Http::assertSentCount(2);
        Http::assertSent(fn ($request): bool => Str::contains(urldecode((string) $request->url()), 'page=2'));
    });

    it('yields every tv id across pages, deduped, as ints', function (): void {
        config(['services.tmdb.token' => 'test-token']);
        Http::fake(['*api.themoviedb.org*' => Http::sequence()
            ->push(fixtureBytes('Catalog/tmdb/tv_changes_page1.json'))
            ->push(fixtureBytes('Catalog/tmdb/tv_changes_page2.json'))]);

        // The generator skips ids it has already seen, so its keys are not
        // guaranteed contiguous — drain with preserve_keys: false to get the list
        // the array-only expectations below need. (PHP 8.2+ iterator_to_array also
        // accepts a plain array, so the stream contract needs its own assertion.)
        $ids = resolve(TmdbApiService::class)->changedTvIds('2026-06-13', '2026-06-14');
        $result = iterator_to_array($ids, false);

        expect($ids)->toBeInstanceOf(Generator::class)
            ->and($result)->toContain(23310, 325296, 325358, 314402)
            ->and($result)->each->toBeInt()
            ->and(array_keys($result, 325296, true))->toHaveCount(1)
            ->and($result)->toHaveCount(4);
    });

    it('drops an adult result from the tv feed too', function (): void {
        config(['services.tmdb.token' => 'test-token']);
        // Both public methods return the same private generator, so the filter
        // has to live there rather than on the movie path alone.
        Http::fake(['*api.themoviedb.org*' => Http::response(json_encode([
            'results' => [
                ['id' => 23310, 'adult' => false, 'softcore' => false],
                ['id' => 999997, 'adult' => true, 'softcore' => false],
            ],
            'page' => 1,
            'total_pages' => 1,
            'total_results' => 2,
        ]))]);

        $result = iterator_to_array(resolve(TmdbApiService::class)->changedTvIds('2026-06-13', '2026-06-14'), false);

        expect($result)->not->toContain(999997)
            ->and($result)->toBe([23310]);
    });
});
