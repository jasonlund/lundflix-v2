<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Actions;

use App\Domains\Catalog\Models\Movie;
use Illuminate\Support\Collection;

final readonly class StampGoneMovies
{
    /**
     * Stamp `tmdb_gone_at` on every movie TMDB answered nothing for, and clear it
     * from every movie it did serve.
     *
     * Clearing on success is what keeps the column a fact about the LAST fetch
     * rather than a retirement — an upstream 404 is routinely temporary, and a
     * stamp that only ever accumulated would strand a restored title. Neither
     * write touches tmdb_synced_at: a vanished movie stays held, so the next run
     * refreshes it instead of re-inserting it.
     *
     * Both go through Eloquent's update() rather than toBase(), so each stamps
     * updated_at and the row lands inside ReindexTouchedRows' watermark — the
     * deliberate opposite of {@see DeferUnresolvedShows}, which leaves updated_at
     * alone because a deferral changes nothing the index holds. A title vanishing
     * or coming back changes what the index should show.
     *
     * Both halves are one bulk statement each, and each is guarded: the caller
     * runs on the sync hot path with up to a whole hydrate batch of ids.
     *
     * @param  Collection<int, int>  $goneIds  ids the hydrate 404'd
     * @param  Collection<int, int>  $presentIds  ids the hydrate served a payload for
     */
    public function handle(Collection $goneIds, Collection $presentIds): void
    {
        if ($goneIds->isNotEmpty()) {
            Movie::query()->whereIn('_tmdb_id', $goneIds)->update(['tmdb_gone_at' => now()]);
        }

        if ($presentIds->isNotEmpty()) {
            Movie::query()
                ->whereIn('_tmdb_id', $presentIds)
                ->whereNotNull('tmdb_gone_at')
                ->update(['tmdb_gone_at' => null]);
        }
    }
}
