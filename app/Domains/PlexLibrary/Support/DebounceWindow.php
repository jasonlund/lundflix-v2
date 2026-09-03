<?php

declare(strict_types=1);

namespace App\Domains\PlexLibrary\Support;

use Carbon\CarbonInterface;

final readonly class DebounceWindow
{
    /**
     * Window lengths stay parameters, never config reads, so each caller owns the
     * windows it debounces on and this predicate holds no configuration knowledge.
     */
    public static function isRipe(
        CarbonInterface $oldest,
        CarbonInterface $newest,
        int $quietSeconds,
        int $deadlineSeconds,
    ): bool {
        $now = now();

        // Two clocks, OR'd: the quiet arm ships a group that stopped growing, the deadline arm one that never goes quiet.
        // `copy()` is required — a mutable Carbon argument would otherwise be shifted under the caller.
        return $newest->copy()->addSeconds($quietSeconds)->lte($now)
            || $oldest->copy()->addSeconds($deadlineSeconds)->lte($now);
    }
}
