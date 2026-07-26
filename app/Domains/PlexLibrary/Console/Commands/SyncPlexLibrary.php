<?php

declare(strict_types=1);

namespace App\Domains\PlexLibrary\Console\Commands;

use App\Domains\PlexLibrary\Models\PlexLibrary;
use App\Domains\PlexLibrary\Models\PlexShow;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

#[Description('Sync the Plex library, re-crawling episodes for changed and un-crawled shows')]
#[Signature('plex:sync')]
final class SyncPlexLibrary extends PlexLibraryCommand
{
    /**
     * Sync crawls the changed set ReconcilePlexShows returned — new shows or shows
     * whose stored _plex_updatedAt / _plex_leafCount moved — plus every show whose
     * episode crawl is behind its own top level.
     *
     * That second arm is what keeps a failed crawl retryable: ReconcilePlexShows
     * writes the incoming _plex_updatedAt before the episodes are fetched, so a
     * show whose fetch then threw looks unchanged forever after. Its
     * episodes_synced_at stayed null (or behind _plex_updatedAt), which puts it
     * back in the crawl until the episodes actually land.
     *
     * @param  Collection<int, PlexLibrary>  $showLibraries
     * @param  list<array{_plex_ratingKey: string, id: int}>  $changed
     * @return Collection<int, PlexShow>
     */
    protected function showsToCrawl(Collection $showLibraries, array $changed): Collection
    {
        return PlexShow::query()
            ->whereIn('plex_library_id', $showLibraries->pluck('id'))
            ->where(function (Builder $query) use ($changed): void {
                $query->whereIn('id', array_column($changed, 'id'))
                    ->orWhereNull('episodes_synced_at')
                    ->orWhereColumn('episodes_synced_at', '<', '_plex_updatedAt');
            })
            ->get();
    }
}
