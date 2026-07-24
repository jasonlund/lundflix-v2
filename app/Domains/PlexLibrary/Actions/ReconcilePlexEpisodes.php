<?php

declare(strict_types=1);

namespace App\Domains\PlexLibrary\Actions;

use App\Domains\PlexLibrary\Models\PlexEpisode;
use App\Domains\PlexLibrary\Models\PlexSeason;
use App\Domains\PlexLibrary\Models\PlexShow;
use App\Domains\PlexLibrary\Support\PlexGuids;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Date;

final class ReconcilePlexEpisodes
{
    /**
     * @param  array<int, array<string, mixed>>  $children
     * @param  array<int, array<string, mixed>>  $allLeaves
     */
    public function handle(PlexShow $show, array $children, array $allLeaves = []): void
    {
        $now = now();
        $seasonRatingKeys = [];

        foreach ($children as $season) {
            // Plex's "All episodes" aggregate (index -1) is a synthetic view, not
            // a real season — index 0 (Specials) is real and kept.
            if (($season['index'] ?? null) === -1) {
                continue;
            }

            $ratingKey = (string) $season['ratingKey'];
            $seasonRatingKeys[] = $ratingKey;

            PlexSeason::query()->updateOrCreate(
                [
                    'plex_server_id' => $show->plex_server_id,
                    '_plex_ratingKey' => $ratingKey,
                ],
                $this->seasonRowFor($show, $season, $now),
            );
        }

        PlexSeason::query()
            ->where('plex_show_id', $show->id)
            ->whereNotIn('_plex_ratingKey', $seasonRatingKeys)
            ->delete();

        $seasonIdsByRatingKey = PlexSeason::query()
            ->where('plex_show_id', $show->id)
            ->pluck('id', '_plex_ratingKey');

        $episodeRatingKeys = [];

        foreach ($allLeaves as $episode) {
            $ratingKey = (string) $episode['ratingKey'];
            $episodeRatingKeys[] = $ratingKey;

            // An episode whose parentRatingKey matches no kept season links to
            // null rather than being dropped — real Plex payloads can emit an
            // episode ahead of (or without) its season, and it must still persist.
            $seasonId = $seasonIdsByRatingKey[(string) $episode['parentRatingKey']] ?? null;

            PlexEpisode::query()->updateOrCreate(
                [
                    'plex_server_id' => $show->plex_server_id,
                    '_plex_ratingKey' => $ratingKey,
                ],
                $this->episodeRowFor($show, $episode, $seasonId, $now),
            );
        }

        PlexEpisode::query()
            ->where('plex_show_id', $show->id)
            ->whereNotIn('_plex_ratingKey', $episodeRatingKeys)
            ->delete();
    }

    /**
     * Map one Plex season Metadata item onto the `plex_seasons` columns:
     * source-prefixed `_plex_*` facts stored raw, the Guid[] crosswalk
     * normalized into the queryable `_tvdb_id`.
     *
     * @param  array<string, mixed>  $season
     * @return array<string, mixed>
     */
    private function seasonRowFor(PlexShow $show, array $season, CarbonInterface $now): array
    {
        $ids = PlexGuids::extract($season);

        return [
            'plex_show_id' => $show->id,
            '_plex_guid' => $season['guid'] ?? null,
            '_plex_index' => $season['index'] ?? null,
            '_plex_title' => $season['title'] ?? null,
            '_plex_leafCount' => $season['leafCount'] ?? null,
            '_plex_addedAt' => isset($season['addedAt']) ? Date::createFromTimestamp($season['addedAt']) : null,
            '_plex_updatedAt' => isset($season['updatedAt']) ? Date::createFromTimestamp($season['updatedAt']) : null,
            '_tvdb_id' => $ids['tvdb'],
            'synced_at' => $now,
        ];
    }

    /**
     * Map one Plex episode Metadata item onto the `plex_episodes` columns:
     * source-prefixed `_plex_*` facts stored raw, the Guid[] crosswalk
     * normalized into the queryable `_imdb_id`/`_tmdb_id`/`_tvdb_id`.
     *
     * @param  array<string, mixed>  $episode
     * @return array<string, mixed>
     */
    private function episodeRowFor(PlexShow $show, array $episode, ?int $seasonId, CarbonInterface $now): array
    {
        $ids = PlexGuids::extract($episode);

        return [
            'plex_show_id' => $show->id,
            'plex_season_id' => $seasonId,
            '_plex_guid' => $episode['guid'] ?? null,
            '_plex_parentIndex' => $episode['parentIndex'] ?? null,
            '_plex_index' => $episode['index'] ?? null,
            '_plex_title' => $episode['title'] ?? null,
            '_plex_addedAt' => isset($episode['addedAt']) ? Date::createFromTimestamp($episode['addedAt']) : null,
            '_plex_updatedAt' => isset($episode['updatedAt']) ? Date::createFromTimestamp($episode['updatedAt']) : null,
            '_plex_guids' => $episode['Guid'] ?? null,
            '_imdb_id' => $ids['imdb'],
            '_tmdb_id' => $ids['tmdb'],
            '_tvdb_id' => $ids['tvdb'],
            'synced_at' => $now,
        ];
    }
}
