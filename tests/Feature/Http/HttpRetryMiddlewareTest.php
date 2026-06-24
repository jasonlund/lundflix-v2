<?php

declare(strict_types=1);

use App\Providers\HttpClientServiceProvider;
use Illuminate\Support\Facades\Http;

/*
|--------------------------------------------------------------------------
| Global HTTP retry middleware
|--------------------------------------------------------------------------
| These tests exercise the GLOBAL outbound-HTTP retry middleware generically,
| not any one service. A plain Illuminate\Support\Facades\Http call against an
| arbitrary faked host (retry.test) must transparently retry a transient
| 429/5xx through to the eventual 200, while a first-try 200 is sent exactly
| once. Synthetic bodies/statuses are used deliberately — transient HTTP error
| responses can't be captured as real-data fixtures. Retry-After is pinned to
| 0 so the suite stays sleep-free.
*/

it('retries a transient 429 through to the eventual 200', function (): void {
    Http::fake(['retry.test/*' => Http::sequence()
        ->push('', 429, ['Retry-After' => '0'])
        ->push('{"ok":true}', 200)]);

    $response = Http::get('https://retry.test/ping');

    Http::assertSentCount(2);
    expect($response->json())->toBe(['ok' => true]);
});

it('retries a transient 500 through to the eventual 200', function (): void {
    Http::fake(['retry.test/*' => Http::sequence()
        ->push('', 500)
        ->push('{"ok":true}', 200)]);

    $response = Http::get('https://retry.test/ping');

    Http::assertSentCount(2);
    expect($response->json())->toBe(['ok' => true]);
});

it('retries a 5xx outside the library default status filter', function (): void {
    Http::fake(['retry.test/*' => Http::sequence()
        ->push('', 502)
        ->push('{"ok":true}', 200)]);

    $response = Http::get('https://retry.test/ping');

    Http::assertSentCount(2);
    expect($response->json())->toBe(['ok' => true]);
});

it('sends a first-try 200 only once', function (): void {
    Http::fake(['retry.test/*' => Http::response('{"ok":true}', 200)]);

    Http::get('https://retry.test/ping');

    Http::assertSentCount(1);
});

it('caps retries at two attempts on a persistent 500', function (): void {
    Http::fake(['retry.test/*' => Http::response('', 500)]);

    Http::get('https://retry.test/ping');

    Http::assertSentCount(3);
});

it('caps retries at two attempts on a persistent 429', function (): void {
    Http::fake(['retry.test/*' => Http::response('', 429, ['Retry-After' => '0'])]);

    Http::get('https://retry.test/ping');

    Http::assertSentCount(3);
});

it('does not retry a 404', function (): void {
    Http::fake(['retry.test/*' => Http::response('', 404)]);

    Http::get('https://retry.test/ping');

    Http::assertSentCount(1);
});

/*
|--------------------------------------------------------------------------
| Connection-timeout retry
|--------------------------------------------------------------------------
| Connection-timeout retry is the library's job and is exercised only in
| production — it's un-fakeable through Http::fake() because Laravel
| synchronously wait()s faked connection failures below the global
| middleware, so the retry loop never sees them as async rejections. We
| therefore don't test the vendor retry loop; instead we assert that OUR
| middleware is applied and configured correctly (below).
*/

it('registers a global retry middleware on the HTTP client', function (): void {
    $middleware = Http::getGlobalMiddleware();

    expect($middleware)->not->toBeEmpty();
});

it('configures connection-timeout retry capped at two attempts', function (): void {
    $options = HttpClientServiceProvider::retryOptions();

    expect($options)->toMatchArray([
        'retry_on_timeout' => true,
        'max_retry_attempts' => 2,
    ])
        ->and($options['default_retry_multiplier'])->toBe((float) config('services.http_retry.retry_multiplier'))
        ->and($options['should_retry_callback'])->toBeCallable();
});
