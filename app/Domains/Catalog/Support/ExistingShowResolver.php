<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Support;

use App\Domains\Catalog\Models\Show;
use Illuminate\Support\Collection;

/**
 * Resolves incoming source payloads to the existing Show that already anchors any
 * of their source ids (imdb, tmdb, tvdb), so a second source merges onto the row
 * a first source created rather than inserting a duplicate. Source-agnostic: it
 * works purely off the extracted {@see SourceIds} triples, leaving each ingestor
 * responsible for pulling those ids out of its own payload shape.
 */
final class ExistingShowResolver
{
    /**
     * Load every existing Show that shares any of the source ids present in the
     * batch, in one query, so each payload can be matched without a per-row lookup.
     *
     * @param  array<int, SourceIds>  $batch
     * @return Collection<int, Show>
     */
    public function loadCandidates(array $batch): Collection
    {
        $imdbIds = $this->collect($batch, static fn (SourceIds $ids): ?string => $ids->imdbId);
        $tmdbIds = $this->collect($batch, static fn (SourceIds $ids): ?int => $ids->tmdbId);
        $tvdbIds = $this->collect($batch, static fn (SourceIds $ids): ?int => $ids->tvdbId);

        if ($imdbIds === [] && $tmdbIds === [] && $tvdbIds === []) {
            return collect();
        }

        return Show::query()
            ->where(function ($query) use ($imdbIds, $tmdbIds, $tvdbIds): void {
                $query->whereIn('_imdb_id', $imdbIds)
                    ->orWhereIn('_tmdb_id', $tmdbIds)
                    ->orWhereIn('_tvdb_id', $tvdbIds);
            })
            ->get();
    }

    /**
     * Match one payload's ids against the loaded candidates by any of its non-null
     * source ids. Returns null when none match.
     *
     * @param  Collection<int, Show>  $candidates
     */
    public function match(SourceIds $ids, Collection $candidates): ?Show
    {
        return $candidates->first(static fn (Show $show): bool => ($ids->imdbId !== null && $show->_imdb_id === $ids->imdbId)
            || ($ids->tmdbId !== null && $show->_tmdb_id === $ids->tmdbId)
            || ($ids->tvdbId !== null && $show->_tvdb_id === $ids->tvdbId));
    }

    /**
     * @param  array<int, SourceIds>  $batch
     * @param  callable(SourceIds): (int|string|null)  $extractor
     * @return list<int|string>
     */
    private function collect(array $batch, callable $extractor): array
    {
        return array_values(array_filter(
            array_map($extractor, $batch),
            static fn (int|string|null $id): bool => $id !== null,
        ));
    }
}
