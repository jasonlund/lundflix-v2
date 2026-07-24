<?php

declare(strict_types=1);

namespace App\Domains\PlexLibrary\Console\Commands;

use App\Domains\PlexLibrary\Models\PlexLibrary;
use App\Domains\PlexLibrary\Models\PlexShow;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Support\Collection;

#[Description('Sync the Plex library, re-crawling episodes only for changed shows')]
#[Signature('plex:sync')]
final class SyncPlexLibrary extends PlexLibraryCommand
{
    /**
     * Sync crawls only the changed set ReconcilePlexShows returned — new shows or
     * shows whose stored _plex_updatedAt / _plex_leafCount moved.
     *
     * @param  Collection<int, PlexLibrary>  $showLibraries
     * @param  list<array{_plex_ratingKey: string, id: int}>  $changed
     * @return Collection<int, PlexShow>
     */
    protected function showsToCrawl(Collection $showLibraries, array $changed): Collection
    {
        return PlexShow::query()
            ->whereIn('id', array_column($changed, 'id'))
            ->get();
    }
}
