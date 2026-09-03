<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Support;

use Carbon\CarbonImmutable;

final class RetryBackoff
{
    /** The interval after a row's first unresolved attempt. Every later one doubles it. */
    private const int FIRST_INTERVAL_DAYS = 1;

    /**
     * The interval stops doubling here. A ceiling, not a retirement: a `/find` miss
     * is genuinely retryable — TMDB may publish the crosswalk later — so the row
     * must keep coming back, just a handful of times a year rather than twice a day.
     */
    private const int MAX_INTERVAL_DAYS = 64;

    /**
     * The doublings that reach MAX_INTERVAL_DAYS. It bounds the SHIFT, which the
     * ceiling above cannot: an unclamped attempt count shifts past the integer
     * width and wraps to a negative interval — a row scheduled into the past and
     * retried at full rate, silently.
     */
    private const int MAX_DOUBLINGS = 6;

    /**
     * When a row attempted $attempts times without resolving becomes a candidate again.
     *
     * Materialized as an instant rather than derived from the counter at read time:
     * the candidate query has to key on it, and SQL that raises 2 to a column's power
     * is neither indexable nor portable to the sqlite the suite runs on.
     */
    public static function until(int $attempts): CarbonImmutable
    {
        $doublings = min(max(0, $attempts - 1), self::MAX_DOUBLINGS);

        $days = min(self::FIRST_INTERVAL_DAYS << $doublings, self::MAX_INTERVAL_DAYS);

        return CarbonImmutable::now()->addDays($days);
    }
}
