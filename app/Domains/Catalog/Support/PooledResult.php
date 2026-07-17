<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Support;

use App\Domains\Catalog\Services\Concerns\PoolsIdBatches;

/**
 * Outcome of a {@see PoolsIdBatches::pooled()}
 * batch: the input-ordered id → raw body map (null on 404) plus the ids that failed
 * (non-404 http/connection failure or a host-signalled PooledIdFailed).
 */
final readonly class PooledResult
{
    /**
     * @param  array<int|string, array<string,mixed>|null>  $results
     * @param  list<int|string>  $failedIds
     */
    public function __construct(public array $results, public array $failedIds) {}
}
