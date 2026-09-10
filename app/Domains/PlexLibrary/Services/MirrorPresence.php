<?php

declare(strict_types=1);

namespace App\Domains\PlexLibrary\Services;

use App\Domains\Catalog\Data\UnitRef;
use App\Domains\Catalog\Enums\UnitKind;
use App\Domains\Catalog\Models\Episode;
use App\Domains\Catalog\Models\Movie;
use App\Domains\PlexLibrary\Contracts\ReportsPresence;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Support\Collection;
use Override;

final readonly class MirrorPresence implements ReportsPresence
{
    // Asking the batch about one unit costs a collection pipeline over a
    // single-element list in front of the same single read, and buys one
    // dispatch over UnitKind instead of two that could drift apart.
    #[Override]
    public function has(UnitRef $unit): bool
    {
        return $this->present([$unit])->isNotEmpty();
    }

    /**
     * @param  iterable<UnitRef>  $units
     * @return Collection<int, UnitRef>
     */
    #[Override]
    public function present(iterable $units): Collection
    {
        return collect($units)
            ->groupBy(fn (UnitRef $unit): string => $unit->kind->value)
            ->flatMap(function (Collection $refs, string $kind): Collection {
                $ids = $refs->map(fn (UnitRef $unit): int => $unit->id)->unique()->values()->all();

                // The sweeps batch every liked title, so one read per kind is the
                // difference between one round trip and thousands.
                $mirrored = match (UnitKind::from($kind)) {
                    UnitKind::Movie => $this->mirroredMovieIds($ids),
                    UnitKind::Episode => $this->mirroredEpisodeIds($ids),
                };

                // Ids only identify a unit alongside its kind, so the membership set
                // is built and filtered inside the kind's own bucket.
                $present = $mirrored->flip();

                return $refs->filter(fn (UnitRef $unit): bool => $present->has($unit->id));
            })
            ->values();
    }

    /**
     * @param  list<int>  $ids
     * @return Collection<int, int>
     */
    private function mirroredMovieIds(array $ids): Collection
    {
        return Movie::query()
            ->whereIn('movies.id', $ids)
            ->whereExists(function (Builder $mirror): void {
                $mirror
                    ->selectRaw('1')
                    ->from('plex_movies')
                    ->where(function (Builder $crosswalk): void {
                        // Either crosswalk id is enough: a mirror row carries whichever
                        // ids the server's own metadata agent resolved, often just one.
                        // The is-not-null guards spell out that an unresolved catalog
                        // id is not agreement, rather than leaning on SQL's null
                        // comparison to imply it.
                        $crosswalk
                            ->where(function (Builder $tmdb): void {
                                $tmdb
                                    ->whereNotNull('movies._tmdb_id')
                                    ->whereColumn('plex_movies._tmdb_id', 'movies._tmdb_id');
                            })
                            ->orWhere(function (Builder $imdb): void {
                                $imdb
                                    ->whereNotNull('movies._imdb_id')
                                    ->whereColumn('plex_movies._imdb_id', 'movies._imdb_id');
                            });
                    });
            })
            ->pluck('movies.id');
    }

    /**
     * @param  list<int>  $ids
     * @return Collection<int, int>
     */
    private function mirroredEpisodeIds(array $ids): Collection
    {
        return Episode::query()
            ->join('shows', 'shows.id', '=', 'episodes.show_id')
            ->whereIn('episodes.id', $ids)
            ->whereExists(function (Builder $mirror): void {
                $mirror
                    ->selectRaw('1')
                    ->from('plex_episodes')
                    ->where(function (Builder $match): void {
                        $match
                            ->where($this->episodeMatchesByCrosswalk(...))
                            ->orWhere($this->episodeMatchesByPosition(...));
                    });
            })
            ->pluck('episodes.id');
    }

    /**
     * The is-not-null guard spells out that an unresolved catalog id is not
     * agreement, rather than leaning on SQL's null comparison to imply it.
     */
    private function episodeMatchesByCrosswalk(Builder $crosswalk): void
    {
        $crosswalk
            ->whereNotNull('episodes._tvdb_id')
            ->whereColumn('plex_episodes._tvdb_id', 'episodes._tvdb_id');
    }

    /**
     * A mirror row whose metadata agent resolved no guid can only be matched by
     * its place in the show, and reading it as absent would refetch an episode
     * we already hold.
     */
    private function episodeMatchesByPosition(Builder $positional): void
    {
        $positional
            ->whereNull('plex_episodes._tvdb_id')
            ->whereNotNull('episodes._tvdb_seasonNumber')
            ->whereNotNull('episodes._tvdb_number')
            ->whereColumn('plex_episodes._plex_parentIndex', 'episodes._tvdb_seasonNumber')
            ->whereColumn('plex_episodes._plex_index', 'episodes._tvdb_number')
            ->whereExists(function (Builder $mirroredShow): void {
                $mirroredShow
                    ->selectRaw('1')
                    ->from('plex_shows')
                    ->whereColumn('plex_shows.id', 'plex_episodes.plex_show_id')
                    ->whereNotNull('shows._tvdb_id')
                    ->whereColumn('plex_shows._tvdb_id', 'shows._tvdb_id');
            });
    }
}
