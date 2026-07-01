<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Services\Concerns;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Shared id-batch pooling skeleton for the Catalog API services (TMDB, TVDB):
 * fan out one request per id, at most `concurrency` in flight at a time, then
 * decode in input order with per-id failure aggregation. The per-service
 * differences are injected via the abstract hooks below; the invariant
 * order/404/aggregate-failure contract lives here.
 *
 * Not used by Common's PlexApiService, whose `poolByServer()` is a deliberately
 * different contract (per-server tolerance, no chunk/dedupe/aggregate-throw).
 */
trait PoolsIdBatches
{
    /**
     * Batch-fetch one request per id, fanning out one {@see Http::pool} per
     * chunk from {@see chunkIds} so at most `concurrency` requests are in flight
     * at once; responses accumulate across chunks (each named after its id via
     * {@see configure}'s shared auth/retry), then decode in input order. A
     * single id's 404 decodes to null without sinking its siblings.
     *
     * Request failures don't short-circuit the batch: both a connection-level
     * failure (a pool entry that comes back as a {@see Throwable} instead of a
     * {@see Response}) and the per-service failure conditions signalled by
     * {@see resolvePooled} are collected per-id, the rest are still decoded, and
     * once the loop completes any failed ids are surfaced together as the single
     * aggregate {@see pooledFailure}. Auth (401) is fatal for the whole batch:
     * {@see resolvePooled} throws it immediately rather than aggregating.
     *
     * @template TKey of int|string
     *
     * @param  array<int, TKey>  $ids
     * @param  callable(PendingRequest, TKey): Response  $build
     * @return array<TKey, array<string, mixed>|null>
     */
    private function pooled(array $ids, callable $build): array
    {
        $ids = array_values(array_unique($ids));

        $responses = [];

        foreach ($this->chunkIds($ids) as $chunk) {
            $responses += Http::pool(fn (Pool $pool): array => array_map(
                fn (int|string $id) => $build($this->configure($pool->as((string) $id)), $id),
                $chunk,
            ));
        }

        $results = [];
        $failedIds = [];

        foreach ($ids as $id) {
            $response = $responses[(string) $id];

            if (! $response instanceof Response) {
                $failedIds[] = $id;

                continue;
            }

            try {
                $results[$id] = $this->resolvePooled($response);
            } catch (PooledIdFailed) {
                $failedIds[] = $id;
            }
        }

        if ($failedIds !== []) {
            throw $this->pooledFailure($failedIds);
        }

        return $results;
    }

    /**
     * Split the input ids into ordered chunks sized by the configured
     * concurrency, so each {@see pooled} fan-out dispatches at most one
     * chunk's worth of concurrent requests. Order is preserved and the final
     * chunk holds the remainder.
     *
     * @template TKey of int|string
     *
     * @param  array<int, TKey>  $ids
     * @return array<int, array<int, TKey>>
     */
    private function chunkIds(array $ids): array
    {
        return array_chunk($ids, max(1, $this->poolConcurrency()));
    }

    /**
     * The configured max concurrent requests per chunk for this service.
     */
    abstract private function poolConcurrency(): int;

    /**
     * Apply the service's shared auth and headers to a pooled pending request.
     */
    abstract private function configure(PendingRequest $request): PendingRequest;

    /**
     * Decode one pooled {@see Response} to its result for {@see pooled}, or throw
     * {@see PooledIdFailed} to collect the id as a per-id failure. Auth or other
     * fatal failures must propagate (not be signalled as {@see PooledIdFailed})
     * so they short-circuit the whole batch.
     *
     * @return array<string, mixed>|null
     */
    abstract private function resolvePooled(Response $response): ?array;

    /**
     * The service's typed aggregate failure for the collected failed ids.
     *
     * @param  array<int, int|string>  $failedIds
     */
    abstract private function pooledFailure(array $failedIds): Throwable;
}
