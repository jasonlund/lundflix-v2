<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Actions;

use App\Domains\Catalog\Models\Show;

final readonly class LinkTvdbEpisodeSeasons
{
    /**
     * Re-derive a show's episode→season links from its current default-type
     * seasons, clearing any that no longer resolve.
     *
     * Its own class so the full-crawl seed path and the incremental per-episode
     * path derive links the same way rather than each carrying a copy that can
     * drift. It reads and writes only local rows — no HTTP — so it is safe to
     * call after any write that may have changed either side of the link.
     */
    public function handle(Show $show): void
    {
        // A null default names no ordering to resolve against, so it matches zero
        // seasons — re-deriving under it would wipe every correct link rather than
        // fix any. Absent knowledge is not evidence the existing links are wrong.
        if ($show->_tvdb_defaultSeasonType === null) {
            return;
        }

        // Episodes carry a raw `seasonNumber` but no local season id, so that number
        // is the only join key back to a season row.
        $seasonIdByNumber = $show->seasons()
            ->where('_tvdb_type->id', $show->_tvdb_defaultSeasonType)
            ->whereNotNull('_tvdb_number')
            ->pluck('id', '_tvdb_number');

        // An episode whose feed `seasonNumber` flipped to null has no numbered group
        // to re-derive under, so the number-keyed pass below would skip it and keep
        // its now-stale link — clear those up front.
        $show->episodes()->whereNull('_tvdb_seasonNumber')->update(['season_id' => null]);

        // Reset to null where no match remains: a changed default type or a season
        // upstream has removed must clear a now-stale link, not keep it.
        $show->episodes()
            ->whereNotNull('_tvdb_seasonNumber')
            ->distinct()
            ->pluck('_tvdb_seasonNumber')
            ->each(fn (int $number) => $show->episodes()
                ->where('_tvdb_seasonNumber', $number)
                ->update(['season_id' => $seasonIdByNumber[$number] ?? null]));
    }
}
