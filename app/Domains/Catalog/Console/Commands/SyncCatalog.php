<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Throwable;

#[Description('Sync the catalog from TMDB and TVDB, then apply IMDb ratings, surviving any single failure')]
#[Signature('sync:catalog {--fresh}')]
class SyncCatalog extends Command
{
    /**
     * The import commands to dispatch, in order: TMDB and TVDB establish the
     * source-of-truth rows first, then IMDb ratings enrich them by _imdb_id last.
     *
     * @var list<class-string<Command>>
     */
    private const array COMMANDS = [SyncTmdbMovies::class, SyncTvdbShows::class, SyncTmdbShows::class, ImportImdbRatings::class];

    public function handle(): int
    {
        $failed = false;

        foreach (self::COMMANDS as $command) {
            try {
                if (Artisan::call($command, [], $this->output) !== self::SUCCESS) {
                    $failed = true;
                }
            } catch (Throwable $e) {
                report($e);
                $failed = true;
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
