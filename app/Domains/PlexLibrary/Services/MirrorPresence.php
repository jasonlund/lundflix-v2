<?php

declare(strict_types=1);

namespace App\Domains\PlexLibrary\Services;

use App\Domains\Catalog\Data\UnitRef;
use App\Domains\Catalog\Enums\UnitKind;
use App\Domains\Catalog\Models\Episode;
use App\Domains\Catalog\Models\Movie;
use App\Domains\PlexLibrary\Contracts\ReportsPresence;
use App\Domains\PlexLibrary\Support\MirrorMatch;
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

                // The acquire sweep batches every queued acquisition, so one read per
                // kind is the difference between one round trip and thousands.
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
                    ->where(MirrorMatch::movie(...));
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
                    ->where(MirrorMatch::episode(...));
            })
            ->pluck('episodes.id');
    }
}
