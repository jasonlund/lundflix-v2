<?php

declare(strict_types=1);

namespace App\Domains\Local\Database;

final readonly class DumpFit
{
    /**
     * Largest row count in [0, $totalRows] whose measured dump stays at or under $cap.
     *
     * $measure must be monotonic non-decreasing in n. Binary search keeps the number
     * of (expensive, real-gzip) measurements to O(log n).
     *
     * @param  callable(int): int  $measure
     */
    public static function largestUnderCap(int $totalRows, int $cap, callable $measure): int
    {
        if ($totalRows === 0) {
            return 0;
        }

        if ($measure($totalRows) <= $cap) {
            return $totalRows;
        }

        $low = 0;
        $high = $totalRows;

        while ($low < $high) {
            $mid = intdiv($low + $high + 1, 2);

            if ($measure($mid) <= $cap) {
                $low = $mid;
            } else {
                $high = $mid - 1;
            }
        }

        return $low;
    }
}
