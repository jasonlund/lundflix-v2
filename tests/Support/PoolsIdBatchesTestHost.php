<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domains\Catalog\Data\PooledResult;
use App\Domains\Catalog\Exceptions\PooledIdFailed;
use App\Domains\Catalog\Services\Concerns\PoolsIdBatches;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use RuntimeException;
use Throwable;

/**
 * Throwaway host exercising the PoolsIdBatches contract in isolation. Mirrors
 * the real services' hooks minimally: 404 → null, non-404 failure → per-id
 * PooledIdFailed, decodable 200 → raw body. `pooled()` is private on the trait,
 * so `fetch()` exposes it for the test to call.
 *
 * The host binds an isolated base URL (a fixed const, not TMDB/TVDB) so its
 * pooled requests can't collide with a real service pattern, and its aggregate
 * failure is a plain RuntimeException naming the failed ids — the trait only
 * needs *a* Throwable, so no service exception is imported.
 */
final readonly class PoolsIdBatchesTestHost
{
    use PoolsIdBatches;

    private const string BASE_URL = 'https://pooled-host.test';

    public function __construct(private int $concurrency = 10) {}

    /**
     * @param  array<int, int|string>  $ids
     */
    public function fetch(array $ids): PooledResult
    {
        return $this->pooled($ids, fn (PendingRequest $request, int|string $id) => $request
            ->get("/item/{$id}"));
    }

    private function poolConcurrency(): int
    {
        return $this->concurrency;
    }

    private function configure(PendingRequest $request): PendingRequest
    {
        return $request->baseUrl(self::BASE_URL)->acceptJson();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolvePooled(Response $response): ?array
    {
        if ($response->notFound()) {
            return null;
        }

        if ($response->failed()) {
            throw new PooledIdFailed;
        }

        return $response->json();
    }

    /**
     * @param  array<int, int|string>  $failedIds
     */
    private function pooledFailure(array $failedIds): Throwable
    {
        return new RuntimeException('failed ids: '.implode(',', $failedIds));
    }
}
