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
    public function handle(): int
    {
        $failed = false;

        foreach ($this->commands() as $command => $arguments) {
            try {
                if (Artisan::call($command, $arguments, $this->output) !== self::SUCCESS) {
                    $failed = true;
                }
            } catch (Throwable $e) {
                report($e);
                $failed = true;
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * The ordered command => arguments to dispatch: TMDB and TVDB establish the
     * source-of-truth rows first, then IMDb ratings enrich them by _imdb_id last.
     *
     * `--fresh` forces a full re-seed: the TVDB step swaps from the updates-only
     * sync to the full allSeries crawl, and --fresh is forwarded to both TMDB
     * syncs to reprocess every already-synced row. Seed and ratings take no
     * --fresh — passing it would error.
     *
     * @return array<class-string<Command>, array<string, bool>>
     */
    private function commands(): array
    {
        if ($this->option('fresh')) {
            return [
                SyncTmdbMovies::class => ['--fresh' => true],
                SeedTvdbShows::class => [],
                SyncTmdbShows::class => ['--fresh' => true],
                ImportImdbRatings::class => [],
            ];
        }

        return [
            SyncTmdbMovies::class => [],
            SyncTvdbShows::class => [],
            SyncTmdbShows::class => [],
            ImportImdbRatings::class => [],
        ];
    }
}
