<?php

declare(strict_types=1);

namespace App\Providers;

use GuzzleRetry\GuzzleRetryMiddleware;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\ServiceProvider;
use Psr\Http\Message\ResponseInterface;

final class HttpClientServiceProvider extends ServiceProvider
{
    /**
     * Maximum number of retry attempts after the initial request, so a
     * persistent failure is attempted at most three times total. This is a
     * fixed, non-secret tunable, so it lives as a const rather than config.
     */
    private const int MAX_RETRY_ATTEMPTS = 2;

    /**
     * Register the global outbound-HTTP retry seam.
     *
     * Every Laravel HTTP client request transparently retries transient
     * 429/500 responses via Guzzle's retry middleware, so no individual
     * service has to opt in. The should_retry_callback (self::shouldRetry)
     * decides retryability and caps attempts at self::MAX_RETRY_ATTEMPTS; any
     * other status (e.g. 404) is treated as definitive.
     *
     * The base delay is config-backed because guzzle-retry sleeps through
     * Guzzle directly and bypasses Laravel's Sleep::fake() — production keeps
     * a real back-off while tests pin HTTP_RETRY_BASE_DELAY=0 to stay
     * sleep-free.
     *
     * Connection-level failures (no HTTP response) are retried via
     * retry_on_timeout, sharing the same MAX_RETRY_ATTEMPTS cap and back-off.
     *
     * The policy options themselves live in self::retryOptions(); boot() only
     * registers the middleware built from them.
     */
    public function boot(): void
    {
        Http::globalMiddleware(GuzzleRetryMiddleware::factory(self::retryOptions()));
    }

    /**
     * The single source of the global outbound-HTTP retry policy options.
     *
     * Returns the guzzle-retry factory configuration: a config-backed base
     * delay, the MAX_RETRY_ATTEMPTS cap, connection-timeout retry, and the
     * should_retry_callback (self::shouldRetry) deciding HTTP-status
     * retryability.
     *
     * @return array{
     *     default_retry_multiplier: float,
     *     max_retry_attempts: int,
     *     retry_on_timeout: bool,
     *     should_retry_callback: callable,
     * }
     */
    public static function retryOptions(): array
    {
        return [
            'default_retry_multiplier' => (float) config('services.http_retry.base_delay'),
            'max_retry_attempts' => self::MAX_RETRY_ATTEMPTS,
            'retry_on_timeout' => true,
            'should_retry_callback' => self::shouldRetry(...),
        ];
    }

    /**
     * Decide whether an outbound request should be retried.
     *
     * Retry policy: a 429 (rate limited) or any 5xx (server error) is treated
     * as transient and retried. Every other status (e.g. 404) is definitive.
     * A null response means a connection-level failure (no HTTP status to
     * inspect), which is not retried here.
     *
     * @param  array<string, mixed>  $options  Guzzle request options for this attempt.
     */
    private static function shouldRetry(array $options, ?ResponseInterface $response = null): bool
    {
        if (! $response instanceof ResponseInterface) {
            return false;
        }

        $statusCode = $response->getStatusCode();

        return $statusCode === 429 || $statusCode >= 500;
    }
}
