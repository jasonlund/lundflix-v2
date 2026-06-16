<?php

use App\Domains\Catalog\Exceptions\TmdbAuthenticationFailed;
use App\Domains\Catalog\Exceptions\TmdbRequestFailed;
use App\Domains\Catalog\Services\TmdbApiService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/*
|--------------------------------------------------------------------------
| Fixture: tests/Fixtures/Catalog/tmdb/movie.json
|--------------------------------------------------------------------------
| Byte-exact live capture of the TMDB movie detail endpoint for id 603
| (The Matrix), in the API's native JSON wire format. Loaded into Http::fake()
| as the response body; never hand-fabricated.
*/

it('sends a Bearer-authed GET to /movie/{id}', function () {
    config(['services.tmdb.token' => 'test-token']);
    Http::fake(['*api.themoviedb.org*' => Http::response(fixtureBytes('Catalog/tmdb/movie.json'))]);

    app(TmdbApiService::class)->movie(603);

    Http::assertSent(fn ($request) => str_contains($request->url(), '/movie/603')
        && $request->hasHeader('Authorization', 'Bearer test-token'));
});

it('appends the movie sub-resources and image-language params', function () {
    config(['services.tmdb.token' => 'test-token']);
    Http::fake(['*api.themoviedb.org*' => Http::response(fixtureBytes('Catalog/tmdb/movie.json'))]);

    app(TmdbApiService::class)->movie(603);

    Http::assertSent(fn ($request) => str_contains(urldecode($request->url()), 'append_to_response=release_dates,images')
        && str_contains(urldecode($request->url()), 'include_image_language=en,null'));
});

it('returns the raw payload unchanged', function () {
    config(['services.tmdb.token' => 'test-token']);
    Http::fake(['*api.themoviedb.org*' => Http::response(fixtureBytes('Catalog/tmdb/movie.json'))]);

    $result = app(TmdbApiService::class)->movie(603);

    expect($result)->toBe(json_decode(fixtureBytes('Catalog/tmdb/movie.json'), true));
});

it('returns null on 404', function () {
    config(['services.tmdb.token' => 'test-token']);
    Http::fake(['*api.themoviedb.org*' => Http::response('', 404)]);

    $result = app(TmdbApiService::class)->movie(999999);

    expect($result)->toBeNull();
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

it('returns an array keyed by the input tmdb ids', function () {
    config(['services.tmdb.token' => 'test-token']);
    Http::fake([
        '*/movie/603*' => Http::response(fixtureBytes('Catalog/tmdb/movie.json')),
        '*/movie/604*' => Http::response(fixtureBytes('Catalog/tmdb/movie.json')),
    ]);

    $result = app(TmdbApiService::class)->movies([603, 604]);

    expect(array_keys($result))->toBe([603, 604]);
});

it('values each id to its raw payload', function () {
    config(['services.tmdb.token' => 'test-token']);
    Http::fake([
        '*/movie/603*' => Http::response(fixtureBytes('Catalog/tmdb/movie.json')),
        '*/movie/604*' => Http::response(fixtureBytes('Catalog/tmdb/movie.json')),
    ]);

    $result = app(TmdbApiService::class)->movies([603, 604]);

    expect($result[603])->toBe(json_decode(fixtureBytes('Catalog/tmdb/movie.json'), true));
});

it('yields null for a 404 id while others still resolve', function () {
    config(['services.tmdb.token' => 'test-token']);
    Http::fake([
        '*/movie/603*' => Http::response(fixtureBytes('Catalog/tmdb/movie.json')),
        '*/movie/999*' => Http::response('', 404),
    ]);

    $result = app(TmdbApiService::class)->movies([603, 999]);

    expect($result[603])->toBe(json_decode(fixtureBytes('Catalog/tmdb/movie.json'), true))
        ->and($result[999])->toBeNull();
});

it('fires one request per id', function () {
    config(['services.tmdb.token' => 'test-token']);
    Http::fake([
        '*/movie/603*' => Http::response(fixtureBytes('Catalog/tmdb/movie.json')),
        '*/movie/604*' => Http::response(fixtureBytes('Catalog/tmdb/movie.json')),
    ]);

    app(TmdbApiService::class)->movies([603, 604]);

    Http::assertSentCount(2);
});

/*
|--------------------------------------------------------------------------
| movies(array $ids): a transport failure inside the pool halts loud
|--------------------------------------------------------------------------
| When one id's request fails at the connection/transport level and exhausts
| retries, Http::pool() places a ConnectionException object at that slot
| (it does not throw). The batch must surface that as a domain
| TmdbRequestFailed — not a raw TypeError from passing the exception to a
| Response-typed decoder — and must evaluate every id before throwing, so a
| multi-id failure reports ALL failed ids. retry_delay=0 exhausts retries
| instantly (shouldRetry() retries a non-RequestException), and the fake
| throws a ConnectionException for the failing id's url.
*/

it('throws TmdbRequestFailed when one id in the batch fails at the transport level', function () {
    config(['services.tmdb.token' => 'test-token', 'services.tmdb.retry_delay' => 0]);
    Http::fake([
        '*/movie/603*' => fn () => throw new ConnectionException('Connection timed out'),
        '*/movie/604*' => Http::response(fixtureBytes('Catalog/tmdb/movie.json')),
    ]);

    $call = fn () => app(TmdbApiService::class)->movies([603, 604]);

    expect($call)->toThrow(TmdbRequestFailed::class);
});

it('reports every failed id when multiple ids in the batch fail at the transport level', function () {
    config(['services.tmdb.token' => 'test-token', 'services.tmdb.retry_delay' => 0]);
    Http::fake([
        '*/movie/603*' => fn () => throw new ConnectionException('Connection timed out'),
        '*/movie/604*' => Http::response(fixtureBytes('Catalog/tmdb/movie.json')),
        '*/movie/605*' => fn () => throw new ConnectionException('Connection timed out'),
    ]);

    $call = fn () => app(TmdbApiService::class)->movies([603, 604, 605]);

    expect($call)->toThrow(TmdbRequestFailed::class, '603')
        ->and($call)->toThrow(TmdbRequestFailed::class, '605');
});

/*
|--------------------------------------------------------------------------
| Fixture: tests/Fixtures/Catalog/tmdb/tv.json
|--------------------------------------------------------------------------
| Byte-exact live capture of the TMDB TV detail endpoint for id 1399
| (Game of Thrones), in the API's native JSON wire format. Loaded into
| Http::fake() as the response body; never hand-fabricated.
*/

it('sends a Bearer-authed GET to /tv/{id}', function () {
    config(['services.tmdb.token' => 'test-token']);
    Http::fake(['*api.themoviedb.org*' => Http::response(fixtureBytes('Catalog/tmdb/tv.json'))]);

    app(TmdbApiService::class)->tv(1399);

    Http::assertSent(fn ($request) => str_contains($request->url(), '/tv/1399')
        && $request->hasHeader('Authorization', 'Bearer test-token'));
});

it('appends the tv sub-resources and image-language params', function () {
    config(['services.tmdb.token' => 'test-token']);
    Http::fake(['*api.themoviedb.org*' => Http::response(fixtureBytes('Catalog/tmdb/tv.json'))]);

    app(TmdbApiService::class)->tv(1399);

    Http::assertSent(fn ($request) => str_contains(urldecode($request->url()), 'append_to_response=images,external_ids,content_ratings')
        && str_contains(urldecode($request->url()), 'include_image_language=en,null'));
});

it('returns the raw tv payload unchanged', function () {
    config(['services.tmdb.token' => 'test-token']);
    Http::fake(['*api.themoviedb.org*' => Http::response(fixtureBytes('Catalog/tmdb/tv.json'))]);

    $result = app(TmdbApiService::class)->tv(1399);

    expect($result)->toBe(json_decode(fixtureBytes('Catalog/tmdb/tv.json'), true));
});

it('returns null on 404 for tv', function () {
    config(['services.tmdb.token' => 'test-token']);
    Http::fake(['*api.themoviedb.org*' => Http::response('', 404)]);

    $result = app(TmdbApiService::class)->tv(999999);

    expect($result)->toBeNull();
});

it('retries a transient 500 and returns the payload from the retry', function () {
    config(['services.tmdb.token' => 'test-token', 'services.tmdb.retry_delay' => 0]);
    Http::fake(['*api.themoviedb.org*' => Http::sequence()
        ->push('', 500)
        ->push(fixtureBytes('Catalog/tmdb/movie.json'), 200)]);

    $result = app(TmdbApiService::class)->movie(603);

    Http::assertSentCount(2);
    expect($result)->toBe(json_decode(fixtureBytes('Catalog/tmdb/movie.json'), true));
});

it('throws TmdbRequestFailed when a 500 persists past retries', function () {
    config(['services.tmdb.token' => 'test-token', 'services.tmdb.retry_delay' => 0]);
    Http::fake(['*api.themoviedb.org*' => Http::response('', 500)]);

    expect(fn () => app(TmdbApiService::class)->movie(603))->toThrow(TmdbRequestFailed::class);
});

it('throws TmdbAuthenticationFailed on a 401 response', function () {
    config(['services.tmdb.token' => 'test-token', 'services.tmdb.retry_delay' => 0]);
    Http::fake(['*api.themoviedb.org*' => Http::response('', 401)]);

    expect(fn () => app(TmdbApiService::class)->movie(603))->toThrow(TmdbAuthenticationFailed::class);
});

it('retries a 429 honoring Retry-After and returns the payload from the retry', function () {
    config(['services.tmdb.token' => 'test-token', 'services.tmdb.retry_delay' => 0]);
    Http::fake(['*api.themoviedb.org*' => Http::sequence()
        ->push('', 429, ['Retry-After' => '0'])
        ->push(fixtureBytes('Catalog/tmdb/movie.json'), 200)]);

    $result = app(TmdbApiService::class)->movie(603);

    expect($result)->toBe(json_decode(fixtureBytes('Catalog/tmdb/movie.json'), true));
});

/*
|--------------------------------------------------------------------------
| Fixture: tests/Fixtures/Catalog/tmdb/find_by_imdb.json
|--------------------------------------------------------------------------
| Byte-exact live capture of the TMDB /find endpoint for the IMDb id
| tt0133093 (The Matrix), with external_source=imdb_id, in the API's native
| JSON wire format. movie_results[0].id=603; all *_results keys present.
| Loaded into Http::fake() as the response body; never hand-fabricated.
*/

it('sends a Bearer-authed GET to /find/{imdbId} with external_source=imdb_id', function () {
    config(['services.tmdb.token' => 'test-token']);
    Http::fake(['*api.themoviedb.org*' => Http::response(fixtureBytes('Catalog/tmdb/find_by_imdb.json'))]);

    app(TmdbApiService::class)->findByImdbId('tt0133093');

    Http::assertSent(fn ($request) => str_contains($request->url(), '/find/tt0133093')
        && str_contains(urldecode($request->url()), 'external_source=imdb_id')
        && $request->hasHeader('Authorization', 'Bearer test-token'));
});

it('returns the whole /find payload unchanged', function () {
    config(['services.tmdb.token' => 'test-token']);
    Http::fake(['*api.themoviedb.org*' => Http::response(fixtureBytes('Catalog/tmdb/find_by_imdb.json'))]);

    $result = app(TmdbApiService::class)->findByImdbId('tt0133093');

    expect($result)->toBe(json_decode(fixtureBytes('Catalog/tmdb/find_by_imdb.json'), true))
        ->and($result)->toHaveKeys(['movie_results', 'tv_results']);
});

it('returns null on 404 for findByImdbId', function () {
    config(['services.tmdb.token' => 'test-token']);
    Http::fake(['*api.themoviedb.org*' => Http::response('', 404)]);

    $result = app(TmdbApiService::class)->findByImdbId('tt9999999');

    expect($result)->toBeNull();
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

it('returns a tv map keyed by the input tmdb ids hitting /tv/{id}', function () {
    config(['services.tmdb.token' => 'test-token']);
    Http::fake([
        '*/tv/1399*' => Http::response(fixtureBytes('Catalog/tmdb/tv.json')),
        '*/tv/1400*' => Http::response(fixtureBytes('Catalog/tmdb/tv.json')),
    ]);

    $result = app(TmdbApiService::class)->tvShows([1399, 1400]);

    expect(array_keys($result))->toBe([1399, 1400])
        ->and($result[1399])->toBe(json_decode(fixtureBytes('Catalog/tmdb/tv.json'), true));
});

it('yields null for a 404 tv id while others still resolve', function () {
    config(['services.tmdb.token' => 'test-token']);
    Http::fake([
        '*/tv/1399*' => Http::response(fixtureBytes('Catalog/tmdb/tv.json')),
        '*/tv/999*' => Http::response('', 404),
    ]);

    $result = app(TmdbApiService::class)->tvShows([1399, 999]);

    expect($result[1399])->toBe(json_decode(fixtureBytes('Catalog/tmdb/tv.json'), true))
        ->and($result[999])->toBeNull();
});

/*
|--------------------------------------------------------------------------
| findManyByImdbId(array $imdbIds): pooled batch /find/{id} lookups
|--------------------------------------------------------------------------
| Fires one /find/{imdbId}?external_source=imdb_id request per id via
| Http::pool() and returns [imdbId => array|null] keyed by the input IMDb id.
| Http::fake() matches by URL (not pool key), so each id gets a distinct url
| pattern in the fake map, all reusing the byte-exact find_by_imdb.json
| (tt0133093) fixture body. A per-id 404 yields null for that id without
| sinking the others.
*/

it('returns a map keyed by the input imdb ids hitting /find/{id} with external_source=imdb_id', function () {
    config(['services.tmdb.token' => 'test-token']);
    Http::fake([
        '*/find/tt0133093*' => Http::response(fixtureBytes('Catalog/tmdb/find_by_imdb.json')),
        '*/find/tt0111161*' => Http::response(fixtureBytes('Catalog/tmdb/find_by_imdb.json')),
    ]);

    $result = app(TmdbApiService::class)->findManyByImdbId(['tt0133093', 'tt0111161']);

    expect(array_keys($result))->toBe(['tt0133093', 'tt0111161'])
        ->and($result['tt0133093'])->toBe(json_decode(fixtureBytes('Catalog/tmdb/find_by_imdb.json'), true));
    Http::assertSent(fn ($request) => str_contains($request->url(), '/find/tt0133093')
        && str_contains(urldecode($request->url()), 'external_source=imdb_id'));
});

it('yields null for a 404 imdb id while others still resolve', function () {
    config(['services.tmdb.token' => 'test-token']);
    Http::fake([
        '*/find/tt0133093*' => Http::response(fixtureBytes('Catalog/tmdb/find_by_imdb.json')),
        '*/find/tt9999999*' => Http::response('', 404),
    ]);

    $result = app(TmdbApiService::class)->findManyByImdbId(['tt0133093', 'tt9999999']);

    expect($result['tt0133093'])->toBe(json_decode(fixtureBytes('Catalog/tmdb/find_by_imdb.json'), true))
        ->and($result['tt9999999'])->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Fixture: tests/Fixtures/Catalog/tmdb/configuration.json
|--------------------------------------------------------------------------
| Byte-exact live capture of the TMDB /configuration endpoint, in the API's
| native JSON wire format. Contains the images base urls and size lists.
| Loaded into Http::fake() as the response body; never hand-fabricated.
*/

it('sends a Bearer-authed GET to /configuration', function () {
    config(['services.tmdb.token' => 'test-token']);
    Http::fake(['*api.themoviedb.org*' => Http::response(fixtureBytes('Catalog/tmdb/configuration.json'))]);

    app(TmdbApiService::class)->configuration();

    Http::assertSent(fn ($request) => str_contains($request->url(), '/configuration')
        && $request->hasHeader('Authorization', 'Bearer test-token'));
});

it('returns the raw configuration payload including images', function () {
    config(['services.tmdb.token' => 'test-token']);
    Http::fake(['*api.themoviedb.org*' => Http::response(fixtureBytes('Catalog/tmdb/configuration.json'))]);

    $result = app(TmdbApiService::class)->configuration();

    expect($result)->toHaveKey('images')
        ->and($result['images']['secure_base_url'])->toBe('https://image.tmdb.org/t/p/')
        ->and($result['images']['poster_sizes'])->not->toBeEmpty();
});

/*
|--------------------------------------------------------------------------
| Fixtures: tests/Fixtures/Catalog/tmdb/movie_changes_page{1,2}.json
|--------------------------------------------------------------------------
| Byte-exact live captures of the TMDB /movie/changes endpoint, in the API's
| native JSON wire format, for the two pages of a paged change feed. page1
| (page:1, total_pages:2) carries results ids 345, 1648226, 1713517; page2
| (page:2, total_pages:2) carries 1713517, 38702, 1712865 — id 1713517 spans
| both pages so the flattened set must dedupe to exactly 5 ids. Loaded into
| Http::fake() as a 2-response sequence to drive pagination; never fabricated.
*/

it('sends a Bearer-authed GET to /movie/changes with start_date/end_date/page params', function () {
    config(['services.tmdb.token' => 'test-token']);
    Http::fake(['*api.themoviedb.org*' => Http::sequence()
        ->push(fixtureBytes('Catalog/tmdb/movie_changes_page1.json'))
        ->push(fixtureBytes('Catalog/tmdb/movie_changes_page2.json'))]);

    app(TmdbApiService::class)->changedMovieIds('2026-06-13', '2026-06-14');

    Http::assertSent(fn ($request) => str_contains($request->url(), '/movie/changes')
        && str_contains(urldecode($request->url()), 'start_date=2026-06-13')
        && str_contains(urldecode($request->url()), 'end_date=2026-06-14')
        && str_contains(urldecode($request->url()), 'page=1')
        && $request->hasHeader('Authorization', 'Bearer test-token'));
});

it('follows pagination across total_pages', function () {
    config(['services.tmdb.token' => 'test-token']);
    Http::fake(['*api.themoviedb.org*' => Http::sequence()
        ->push(fixtureBytes('Catalog/tmdb/movie_changes_page1.json'))
        ->push(fixtureBytes('Catalog/tmdb/movie_changes_page2.json'))]);

    app(TmdbApiService::class)->changedMovieIds('2026-06-13', '2026-06-14');

    Http::assertSentCount(2);
    Http::assertSent(fn ($request) => str_contains(urldecode($request->url()), 'page=2'));
});

it('flattens the results ids across pages into a flat array of ints', function () {
    config(['services.tmdb.token' => 'test-token']);
    Http::fake(['*api.themoviedb.org*' => Http::sequence()
        ->push(fixtureBytes('Catalog/tmdb/movie_changes_page1.json'))
        ->push(fixtureBytes('Catalog/tmdb/movie_changes_page2.json'))]);

    $result = app(TmdbApiService::class)->changedMovieIds('2026-06-13', '2026-06-14');

    expect($result)->toContain(345, 1648226, 1713517, 38702, 1712865)
        ->and($result)->each->toBeInt();
});

it('dedupes an id repeated across pages', function () {
    config(['services.tmdb.token' => 'test-token']);
    Http::fake(['*api.themoviedb.org*' => Http::sequence()
        ->push(fixtureBytes('Catalog/tmdb/movie_changes_page1.json'))
        ->push(fixtureBytes('Catalog/tmdb/movie_changes_page2.json'))]);

    $result = app(TmdbApiService::class)->changedMovieIds('2026-06-13', '2026-06-14');

    expect(array_keys($result, 1713517, true))->toHaveCount(1)
        ->and($result)->toHaveCount(5);
});

/*
|--------------------------------------------------------------------------
| Fixtures: tests/Fixtures/Catalog/tmdb/tv_changes_page{1,2}.json
|--------------------------------------------------------------------------
| Byte-exact live captures of the TMDB /tv/changes endpoint, in the API's
| native JSON wire format, for the two pages of a paged change feed. page1
| (page:1, total_pages:2) carries results ids 23310, 325296; page2
| (page:2, total_pages:2) carries 325296, 325358, 314402 — id 325296 spans
| both pages so the flattened set must dedupe to exactly 4 ids. Loaded into
| Http::fake() as a 2-response sequence to drive pagination; never fabricated.
*/

it('GETs /tv/changes with date/page params and follows total_pages', function () {
    config(['services.tmdb.token' => 'test-token']);
    Http::fake(['*api.themoviedb.org*' => Http::sequence()
        ->push(fixtureBytes('Catalog/tmdb/tv_changes_page1.json'))
        ->push(fixtureBytes('Catalog/tmdb/tv_changes_page2.json'))]);

    app(TmdbApiService::class)->changedTvIds('2026-06-13', '2026-06-14');

    Http::assertSent(fn ($request) => str_contains($request->url(), '/tv/changes')
        && str_contains(urldecode($request->url()), 'start_date=2026-06-13')
        && str_contains(urldecode($request->url()), 'end_date=2026-06-14')
        && str_contains(urldecode($request->url()), 'page=1')
        && $request->hasHeader('Authorization', 'Bearer test-token'));
    Http::assertSentCount(2);
    Http::assertSent(fn ($request) => str_contains(urldecode($request->url()), 'page=2'));
});

it('flattens and dedupes the tv change ids into a flat array of ints', function () {
    config(['services.tmdb.token' => 'test-token']);
    Http::fake(['*api.themoviedb.org*' => Http::sequence()
        ->push(fixtureBytes('Catalog/tmdb/tv_changes_page1.json'))
        ->push(fixtureBytes('Catalog/tmdb/tv_changes_page2.json'))]);

    $result = app(TmdbApiService::class)->changedTvIds('2026-06-13', '2026-06-14');

    expect($result)->toContain(23310, 325296, 325358, 314402)
        ->and($result)->each->toBeInt()
        ->and(array_keys($result, 325296, true))->toHaveCount(1)
        ->and($result)->toHaveCount(4);
});
