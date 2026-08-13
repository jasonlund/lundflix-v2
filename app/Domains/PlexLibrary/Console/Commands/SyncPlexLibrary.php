<?php

declare(strict_types=1);

namespace App\Domains\PlexLibrary\Console\Commands;

use App\Domains\PlexLibrary\Models\PlexShow;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Database\Eloquent\Builder;

#[Description('Sync the Plex library, re-crawling episodes for changed and un-crawled shows')]
#[Signature('plex:sync')]
final class SyncPlexLibrary extends PlexLibraryCommand
{
    /**
     * Sync crawls every show whose episode watermark is behind its own top level:
     * a show ReconcilePlexShows saw move is marked by having its
     * episodes_synced_at nulled, so the watermark alone names the whole set.
     *
     * The watermark is also what keeps a failed crawl retryable: ReconcilePlexShows
     * writes the incoming _plex_updatedAt before the episodes are fetched, so a
     * show whose fetch then threw looks unchanged forever after. Its
     * episodes_synced_at stayed null (or behind _plex_updatedAt), which puts it
     * back in the crawl until the episodes actually land.
     *
     * @param  Builder<PlexShow>  $query
     */
    protected function constrainCrawl(Builder $query): void
    {
        $query->where(function (Builder $query): void {
            $query->whereNull('episodes_synced_at')
                ->orWhereColumn('episodes_synced_at', '<', '_plex_updatedAt');
        });
    }

    protected function notifiesRecentlyAdded(): bool
    {
        return true;
    }
}
