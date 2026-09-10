<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Services\Concerns;

use App\Domains\Catalog\Data\PooledResult;
use App\Domains\Catalog\Exceptions\PooledIdFailed;
use App\Domains\Catalog\Services\PooledTransport;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Throwable;

/**
 * Shared id-batch pooling skeleton for the Catalog API services (TMDB, TVDB):
 * fan out one request per id through a single rolling window at most
 * `concurrency` wide, then decode in input order with per-id failure
 * aggregation. The per-service differences are injected via the abstract hooks
 * below; the invariant order/404/aggregate-failure contract lives here.
 */
trait PoolsIdBatches
{
    /**
     * Batch-fetch one request per id, handing the call's ids to the shared
     * {@see PooledTransport} in one go: it rolls a single window at most
     * `concurrency` wide over them, so a slow id holds up only the next id into
     * its slot rather than a whole batch of siblings, and every id rides the one
     * connection the transport holds open for the process. Results settle out of
     * order and are decoded here in input order. A single id's 404 decodes to
     * null without sinking its siblings.
     *
     * Request failures don't short-circuit the batch: both a connection-level
     * failure (a slot that settles as a {@see Throwable} instead of a
     * {@see Response}) and the per-service failure conditions signalled by
     * {@see resolvePooled} are collected per-id, the rest are still decoded, and
     * once the loop completes any failed ids are surfaced together as the single
     * aggregate {@see pooledFailure} — reported for observability, not thrown, so
     * the batch's successful results are still returned for the callers to upsert.
     *
     * Auth is fatal for the whole batch at either end, and neither end
     * aggregates: {@see configure} may throw while the request is still being
     * built (TVDB exchanges its key for a JWT there), and a 401 makes
     * {@see resolvePooled} throw rather than signal a per-id failure.
     *
     * @template TKey of int|string
     *
     * @param  array<int, TKey>  $ids
     * @param  callable(PendingRequest, TKey): Response  $build
     */
    private function pooled(array $ids, callable $build): PooledResult
    {
        $ids = array_values(array_unique($ids));

        $results = [];
        $failedIds = [];

        $requests = [];
        // Seeded up front so the slot for every id exists before the first
        // callback lands: the window completes out of order, and an id whose
        // request never settles must read as a miss rather than an undefined key.
        $responses = [];

        foreach ($ids as $id) {
            $requests[$id] = fn (PendingRequest $request) => $build($this->configure($request), $id);
            $responses[$id] = null;
        }

        resolve(PooledTransport::class)->fetch(
            $requests,
            function (int|string $id, Response|Throwable $result) use (&$responses): void {
                $responses[$id] = $result;
            },
            $this->poolConcurrency(),
            $this->poolRate(),
        );

        foreach ($ids as $id) {
            $response = $responses[$id];

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
            // Report — don't throw. A transient per-id failure must not discard the
            // whole batch's successful results (that dropped ~1000 good rows per one
            // bad id). Callers upsert the successes; failed ids are logged for a later
            // targeted re-sync.
            report($this->pooledFailure($failedIds));
        }

        return new PooledResult($results, $failedIds);
    }

    /**
     * The configured width of this service's rolling request window.
     */
    abstract private function poolConcurrency(): int;

    /**
     * Requests per second this service's pooled batches are paced at, or null to
     * leave them unpaced.
     *
     * Concrete rather than abstract: a service whose ceiling nobody has measured
     * has nothing to declare, and an unmeasured throttle could only make it
     * slower — so unpaced is the default a service opts out of, not into.
     */
    private function poolRate(): ?float
    {
        return null;
    }

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
