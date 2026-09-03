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
     * source-of-truth rows — the movies leg, then the TVDB show step followed by
     * the episodes sync for already-seeded shows, then the TMDB show hydration.
     * The IMDb datasets are not here: they are far too large to pull on this
     * frequently-run sync and have their own catalog:sync-imdb schedule.
     *
     * The two flows run *different* movies legs, and that is the point. The
     * scheduled twice-daily run must never parse the 1.23M-row TMDB ids export:
     * on production that phase alone exceeded an hour per run, and it does not
     * shrink as the catalog converges, so the cost is paid in full forever. It
     * therefore takes the incremental catalog:sync-movies, and the export
     * survives only as the operator-invoked catalog:seed-movies behind --fresh.
     *
     * `--fresh` forces a full re-seed: the TVDB show step swaps from the
     * updates-only sync to the full allSeries crawl, the movies leg swaps to the
     * seed, and --fresh is forwarded to that seed and to the TMDB show sync to
     * reprocess every already-synced row. The TVDB seed and episodes steps take
     * no --fresh — passing it would error; the marker-driven episodes sync runs
     * identically in both flows. Nor does the incremental catalog:sync-movies
     * accept one, which is why the swap and not a forwarded flag.
     *
     * @return array<class-string<Command>, array<string, bool>>
     */
    private function commands(): array
    {
        if ($this->option('fresh')) {
            return [
                SeedTmdbMovies::class => ['--fresh' => true],
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
