<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Console\Commands\Concerns;

use Carbon\CarbonImmutable;

/**
 * Wall-clock timing for the sync commands' per-phase heartbeat lines, in the two
 * resolutions those lines use: whole seconds for the TMDB/TVDB phases, one decimal
 * for the IMDb legs' `[elapsed …]` lines. Both are an operator's liveness signal,
 * not a benchmark.
 */
trait MeasuresElapsedTime
{
    protected function secondsSince(CarbonImmutable $startedAt): int
    {
        return (int) $startedAt->diffInSeconds(CarbonImmutable::now());
    }

    /**
     * One-decimal wall seconds since a `microtime(true)` reading.
     *
     * Formatted with sprintf rather than `Number::format`: the latter needs
     * ext-intl, which composer.json does not declare, and its locale-aware output
     * would slip a thousands separator into any leg past 999s — a shape none of
     * the sibling heartbeat lines carry.
     *
     * @param  float  $startedAt  the `microtime(true)` reading taken before the work
     */
    protected function preciseSecondsSince(float $startedAt): string
    {
        return sprintf('%.1f', microtime(true) - $startedAt);
    }
}
