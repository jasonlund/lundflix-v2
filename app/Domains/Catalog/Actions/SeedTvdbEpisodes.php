<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Actions;

use App\Domains\Catalog\Models\Show;
use App\Domains\Catalog\Services\TvdbApiService;

final readonly class SeedTvdbEpisodes
{
    public function __construct(
        private TvdbApiService $tvdb,
        private UpsertTvdbEpisodes $upsertEpisodes,
    ) {}

    public function handle(Show $show): int
    {
        $episodes = $this->tvdb->episodes($show->_tvdb_id);

        $this->upsertEpisodes->handle($show, $episodes);

        // Episodes carry a raw `seasonNumber` but no local season id, so resolve
        // `season_id` by matching that number against the show's default-type
        // seasons — the same ordering these episodes were fetched under.
        $seasonIdByNumber = $show->seasons()
            ->where('_tvdb_type->id', $show->_tvdb_defaultSeasonType)
            ->whereNotNull('_tvdb_number')
            ->pluck('id', '_tvdb_number');

        // Re-derive every episode group's `season_id` from the CURRENT default-type
        // seasons, resetting to null where no match remains — a changed default type
        // or removed season must clear a now-stale link on re-seed, not keep it.
        $show->episodes()
            ->whereNotNull('_tvdb_seasonNumber')
            ->distinct()
            ->pluck('_tvdb_seasonNumber')
            ->each(fn (int $number) => $show->episodes()
                ->where('_tvdb_seasonNumber', $number)
                ->update(['season_id' => $seasonIdByNumber[$number] ?? null]));

        $show->update(['episodes_synced_at' => now()]);

        return count($episodes);
    }
}
