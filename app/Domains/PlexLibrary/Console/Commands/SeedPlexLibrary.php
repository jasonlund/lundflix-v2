<?php

declare(strict_types=1);

namespace App\Domains\PlexLibrary\Console\Commands;

use App\Domains\PlexLibrary\Models\PlexLibrary;
use App\Domains\PlexLibrary\Models\PlexShow;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Support\Collection;

#[Description('Seed the full Plex library depth, crawling episodes for every show')]
#[Signature('plex:seed')]
final class SeedPlexLibrary extends PlexLibraryCommand
{
    /**
     * Seed crawls every show in the show libraries, ignoring the changed set.
     *
     * @param  Collection<int, PlexLibrary>  $showLibraries
     * @param  list<array{_plex_ratingKey: string, id: int}>  $changed
     * @return Collection<int, PlexShow>
     */
    protected function showsToCrawl(Collection $showLibraries, array $changed): Collection
    {
        return PlexShow::query()
            ->whereIn('plex_library_id', $showLibraries->pluck('id'))
            ->get();
    }
}
