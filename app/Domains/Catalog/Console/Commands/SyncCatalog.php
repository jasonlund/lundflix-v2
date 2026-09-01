<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Throwable;

#[Description('Sync the catalog from TMDB and TVDB, surviving any single failure')]
#[Signature('catalog:sync {--fresh}')]
class SyncCatalog extends Command
{
    public function handle(): int
    {
        $failedNames = [];

        foreach ($this->commands() as $command => $arguments) {
            $name = resolve($command)->getName();

            $this->output->writeln("Running {$name}…");

            try {
                if (Artisan::call($command, $arguments, $this->output) !== self::SUCCESS) {
                    $failedNames[] = $name;
                }
            } catch (Throwable $e) {
                report($e);
                $failedNames[] = $name;
            }
        }

        // Names the guilty children rather than counting them, so this cannot go
        // through EmitsHeartbeat::failureSummary(): that renders a count, which
        // here would say "1 command failed" of a run whose whole point is that it
        // kept going — leaving the operator to find which one in the interleaved
        // wall of child output.
        if ($failedNames !== []) {
            $this->output->writeln('Failed commands: '.implode(', ', $failedNames));
        }

        $this->output->writeln('Done.');

        return $failedNames === [] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * The ordered command => arguments to dispatch: TMDB and TVDB establish the
     * source-of-truth rows — movies, then the TVDB show sync followed by the
     * episodes sync for already-seeded shows, then the TMDB show hydration. The
     * IMDb datasets are not here: they are far too large to pull on this
     * frequently-run sync and have their own catalog:sync-imdb schedule.
     *
     * `--fresh` forces a full re-seed: the TVDB show step swaps from the
     * updates-only sync to the full allSeries crawl, and --fresh is forwarded to
     * both TMDB syncs to reprocess every already-synced row. The seed and
     * episodes steps take no --fresh — passing it would error; the
     * marker-driven episodes sync runs identically in both flows.
     *
     * @return array<class-string<Command>, array<string, bool>>
     */
    private function commands(): array
    {
        if ($this->option('fresh')) {
            return [
                SyncTmdbMovies::class => ['--fresh' => true],
                SeedTvdbShows::class => [],
                SyncTvdbEpisodes::class => [],
                SyncTmdbShows::class => ['--fresh' => true],
            ];
        }

        return [
            SyncTmdbMovies::class => [],
            SyncTvdbShows::class => [],
            SyncTvdbEpisodes::class => [],
            SyncTmdbShows::class => [],
        ];
    }
}
