<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Data;

use App\Domains\Catalog\Services\PooledTransport;

/**
 * A snapshot of what the shared {@see PooledTransport} has done so far this
 * process: the number of requests it has dispatched across every caller.
 */
final readonly class TransportStats
{
    public function __construct(public int $dispatched) {}
}
