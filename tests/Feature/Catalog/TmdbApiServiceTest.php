<?php

use App\Domains\Catalog\Exceptions\TmdbRequestFailed;
use App\Domains\Catalog\Services\TmdbApiService;
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

it('throws TmdbRequestFailed on a 401 response', function () {
    config(['services.tmdb.token' => 'test-token', 'services.tmdb.retry_delay' => 0]);
    Http::fake(['*api.themoviedb.org*' => Http::response('', 401)]);

    expect(fn () => app(TmdbApiService::class)->movie(603))->toThrow(TmdbRequestFailed::class);
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
