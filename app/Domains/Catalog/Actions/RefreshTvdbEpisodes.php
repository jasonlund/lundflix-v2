<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Actions;

use App\Domains\Catalog\Data\EpisodeRefreshResult;
use App\Domains\Catalog\Models\Show;
use App\Domains\Catalog\Services\TvdbApiService;
use Illuminate\Support\Collection;

final readonly class RefreshTvdbEpisodes
{
    public function __construct(
        private TvdbApiService $tvdb,
        private UpsertTvdbEpisodes $upsertEpisodes,
        private LinkTvdbEpisodeSeasons $linkSeasons,
    ) {}

    /**
     * @param  Collection<int, Show>  $shows  already-seeded shows, keyed by `_tvdb_id`
     * @param  Collection<int, int>  $episodeIds  the changed TVDB episode ids
     */
    public function handle(Collection $shows, Collection $episodeIds): EpisodeRefreshResult
    {
        $pooled = $this->tvdb->episodesMany($episodeIds->all());

        $payloadsByShow = collect($pooled->results)
            // A null result is a 404: the episode is gone upstream, a settled answer
            // rather than a fetch to re-cover, so it counts as neither a persist nor
            // a failure.
            ->filter()
            ->map(fn (array $result): array => $result['data'])
            // Each payload names its own series, and that is what it is attributed
            // to — the feed hands over bare episode ids, and an episode may have
            // moved to another show since we last saw it. A payload naming a series
            // this run isn't refreshing has no show to write to and is dropped.
            ->groupBy('seriesId')
            ->filter(fn (Collection $payloads, int|string $seriesId): bool => $shows->has($seriesId));

        $persisted = 0;

        foreach ($payloadsByShow as $seriesId => $payloads) {
            $show = $shows->get($seriesId);

            $persisted += $this->upsertEpisodes->handle($show, $payloads->all());

            $this->linkSeasons->handle($show);

            $show->update(['episodes_synced_at' => now()]);
        }

        return new EpisodeRefreshResult($persisted, count($pooled->failedIds));
    }
}
