<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Console\Commands\Concerns;

use Carbon\CarbonImmutable;

/**
 * Wall-clock timing for the sync commands' per-phase heartbeat lines. Whole
 * seconds only: the number is an operator's liveness signal, not a benchmark.
 */
trait MeasuresElapsedTime
{
    protected function secondsSince(CarbonImmutable $startedAt): int
    {
        return (int) $startedAt->diffInSeconds(CarbonImmutable::now());
    }
}
