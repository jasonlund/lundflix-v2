<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Actions;

use App\Domains\Catalog\Models\Show;
use App\Domains\Catalog\Support\RetryBackoff;
use Illuminate\Support\Collection;

class DeferUnresolvedShows
{
    /**
     * Count one unresolved attempt against every row of $shows the hydrate chunk
     * left without a `tmdb_synced_at` stamp, and push each one's next attempt out
     * by {@see RetryBackoff}. Returns how many were deferred.
     *
     * The outcome is re-read from the database rather than taken from the caller:
     * the chunk's models were loaded before the reconcile stamped `_tmdb_id` and
     * before the hydrate stamped `tmdb_synced_at`, so they are stale by now and
     * only the row itself says whether it resolved.
     *
     * @param  Collection<int, Show>  $shows  the candidates the chunk just attempted
     */
    public function handle(Collection $shows): int
    {
        $unresolved = Show::query()
            ->whereKey($shows->pluck('id')->all())
            ->whereNull('tmdb_synced_at')
            ->select(['id', 'tmdb_unresolved_attempts'])
            ->get();

        // Grouped by the counter, so each distinct value writes once: the interval
        // is per-row but takes only a handful of values, and a per-row update would
        // multiply statements by the chunk size for nothing.
        foreach ($unresolved->groupBy('tmdb_unresolved_attempts') as $attempts => $rows) {
            $attempted = (int) $attempts + 1;

            Show::query()
                ->whereKey($rows->pluck('id')->all())
                // toBase(), so updated_at is left alone: it is the leg's reindex
                // watermark, and deferring changes nothing the search index holds.
                ->toBase()
                ->update([
                    'tmdb_unresolved_attempts' => $attempted,
                    'tmdb_retry_after' => RetryBackoff::until($attempted),
                ]);
        }

        return $unresolved->count();
    }
}
