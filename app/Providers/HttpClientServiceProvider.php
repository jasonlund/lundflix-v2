<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domains\Catalog\Services\PooledTransport;
use App\Domains\Common\Support\HttpStatus;
use GuzzleRetry\GuzzleRetryMiddleware;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\ServiceProvider;
use Override;
use Psr\Http\Message\ResponseInterface;

final class HttpClientServiceProvider extends ServiceProvider
{
    /**
     * Retries after the initial request, so a persistent failure runs at most
     * three times total. Fixed non-secret tunable → const, not config.
     */
    private const int MAX_RETRY_ATTEMPTS = 2;

    /**
     * One transport for the whole process, so every pooled fan-out reuses the
     * same connections instead of re-handshaking per batch.
     */
    #[Override]
    public function register(): void
    {
        $this->app->singleton(PooledTransport::class);
    }

    /**
     * Register the global outbound-HTTP retry seam: every Laravel HTTP request
     * transparently retries transient 429/5xx responses and connection timeouts,
     * so no service opts in. Policy lives in {@see retryOptions}.
     */
    public function boot(): void
    {
        Http::globalMiddleware(GuzzleRetryMiddleware::factory(self::retryOptions()));
    }

    /**
     * Single source of the global retry policy: config-backed base delay,
     * {@see MAX_RETRY_ATTEMPTS} cap, connection-timeout retry, and
     * {@see shouldRetry} for HTTP-status retryability.
     *
     * @return array{
     *     default_retry_multiplier: float,
     *     max_retry_attempts: int,
     *     retry_on_timeout: bool,
     *     retry_on_status: list<int>,
     *     should_retry_callback: callable,
     * }
     */
    public static function retryOptions(): array
    {
        return [
            // Per-retry backoff multiplier: waits multiplier × retry_count seconds
            // before each attempt (escalating, not flat).
            'default_retry_multiplier' => (float) config('services.http_retry.retry_multiplier'),
            'max_retry_attempts' => self::MAX_RETRY_ATTEMPTS,
            'retry_on_timeout' => true,
            // Empty so the library does no status pre-filtering: shouldRetry is the
            // single source of truth for HTTP-status retryability (retry any 5xx/429).
            'retry_on_status' => [],
            'should_retry_callback' => self::shouldRetry(...),
        ];
    }

    /**
     * Retry only transient HTTP statuses: 429 or any 5xx. A null response is a
     * connection-level failure (retried separately via retry_on_timeout), not
     * here.
     *
     * @param  array<string, mixed>  $options
     */
    private static function shouldRetry(array $options, ?ResponseInterface $response = null): bool
    {
        if (! $response instanceof ResponseInterface) {
            return false;
        }

        return HttpStatus::isRetryable($response->getStatusCode());
    }
}
