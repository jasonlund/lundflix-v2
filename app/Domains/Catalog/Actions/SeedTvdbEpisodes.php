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
        $show->seasons()
            ->where('_tvdb_type->id', $show->_tvdb_defaultSeasonType)
            ->pluck('id', '_tvdb_number')
            ->each(fn (int $seasonId, int $number) => $show->episodes()
                ->where('_tvdb_seasonNumber', $number)
                ->update(['season_id' => $seasonId]));

        $show->update(['episodes_synced_at' => now()]);

        return count($episodes);
    }
}
