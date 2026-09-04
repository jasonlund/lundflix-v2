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
        private LinkTvdbEpisodeSeasons $linkSeasons,
    ) {}

    public function handle(Show $show): int
    {
        $episodes = $this->tvdb->episodes($show->_tvdb_id);

        $this->upsertEpisodes->handle($show, $episodes);

        $this->linkSeasons->handle($show);

        $show->update(['episodes_synced_at' => now()]);

        return count($episodes);
    }
}
