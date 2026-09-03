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
final class SyncCatalog extends Command
{
    public function handle(): int
    {
        $failedNames = [];

        foreach ($this->commands() as $command => $arguments) {
            // The class-string stands in until the friendly name resolves. Reading that
            // name builds the command through the container, which can throw — inside
            // the try that costs one leg; outside it would kill every remaining leg and
            // the closing summary, in the one command that exists to survive a failure.
            $name = $command;

            try {
                $name = $this->commandName($command);

                $this->output->writeln("Running {$name}…");

                $exitCode = Artisan::call($command, $arguments, $this->output);

                if ($exitCode !== self::SUCCESS) {
                    $this->output->writeln("{$name} failed with exit code {$exitCode}");
                    $failedNames[] = $name;
                }
            } catch (Throwable $e) {
                report($e);
                $this->output->writeln("{$name} failed: {$e->getMessage()}");
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
     * The artisan signature behind a class-string key — `catalog:sync-movies`, the
     * name an operator can re-run, rather than an FQCN they would have to translate.
     * Resolving it is container work that can throw, so callers read it from inside
     * their own failure handling.
     *
     * @param  class-string<Command>  $command
     */
    private function commandName(string $command): string
    {
        return resolve($command)->getName() ?? $command;
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
