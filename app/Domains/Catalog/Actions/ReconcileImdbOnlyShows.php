<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Actions;

use App\Domains\Catalog\Exceptions\TmdbShowCrosswalkCollision;
use App\Domains\Catalog\Models\Show;
use App\Domains\Catalog\Services\TmdbApiService;
use Illuminate\Support\Collection;

class ReconcileImdbOnlyShows
{
    /**
     * Resolve tmdb ids for the chunk's imdb-only rows through /find, stamping each
     * resolved id onto its row. An imdb id whose result has no tv_results stays
     * TVDB-only — no stamp. Returns the resolved `_tmdb_id` set the caller hydrates.
     *
     * @param  Collection<int, Show>  $shows
     * @return array<int, int>
     */
    public function handle(Collection $shows, TmdbApiService $api): array
    {
        $imdbOnly = $shows->whereNull('_tmdb_id')
            ->filter(fn (Show $show): bool => $show->_imdb_id !== null)
            ->values();

        if ($imdbOnly->isEmpty()) {
            return [];
        }

        $results = $api->findManyByImdbId($imdbOnly->pluck('_imdb_id')->unique()->values()->all());

        $resolvedIds = [];

        // Iterate the candidate ROWS, not the /find results: two rows may legitimately
        // share one `_imdb_id`, and a single resolved tmdb id can only ever be stamped
        // onto one of them (the UNIQUE `_tmdb_id`). Stamp per row, scoped to its PK.
        foreach ($imdbOnly as $show) {
            $tvResults = $results[$show->_imdb_id]['tv_results'] ?? [];

            if ($tvResults === []) {
                continue;
            }

            $tmdbId = (int) $tvResults[0]['id'];

            // The UNIQUE `_tmdb_id` guard: a resolved id already claimed by another
            // row — including a sibling row sharing this `_imdb_id` that we stamped
            // earlier this chunk — can't be re-pointed onto this one. Skip + report and
            // leave the row TVDB-only (same accepted outcome as an empty tv_results)
            // rather than let the constraint violation abort the whole chunk.
            if (Show::query()->where('_tmdb_id', $tmdbId)->exists()) {
                report(TmdbShowCrosswalkCollision::forResolvedId($show->_imdb_id, $tmdbId));

                continue;
            }

            $stamped = Show::query()
                ->where('id', $show->getKey())
                ->whereNull('_tmdb_id')
                ->update(['_tmdb_id' => $tmdbId]);

            // Only hydrate an id we actually stamped — a raced-away row (0 affected)
            // never carried it.
            if ($stamped === 0) {
                continue;
            }

            $resolvedIds[] = $tmdbId;
        }

        return $resolvedIds;
    }
}
