<?php

declare(strict_types=1);

namespace App\Domains\PlexLibrary\Console\Commands;

use App\Domains\PlexLibrary\Models\PlexShow;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Database\Eloquent\Builder;

#[Description('Seed the full Plex library depth, crawling episodes for every show')]
#[Signature('plex:seed')]
final class SeedPlexLibrary extends PlexLibraryCommand
{
    /**
     * Seed crawls every show in the show libraries, watermark irrelevant.
     *
     * @param  Builder<PlexShow>  $query
     */
    protected function constrainCrawl(Builder $query): void
    {
        // no predicate: the whole show set is the crawl set
    }

    protected function notifiesRecentlyAdded(): bool
    {
        return false;
    }
}
