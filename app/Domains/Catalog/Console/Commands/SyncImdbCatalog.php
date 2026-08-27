<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Console\Commands;

use App\Domains\Catalog\Actions\ReindexTouchedRows;
use App\Domains\Catalog\Console\Commands\Concerns\MeasuresElapsedTime;
use App\Domains\Catalog\Models\Movie;
use App\Domains\Catalog\Models\Show;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Throwable;

#[Description('Sync the IMDb datasets — ratings, titles, akas — in order, surviving any single failure')]
#[Signature('catalog:sync-imdb {--force}')]
final class SyncImdbCatalog extends Command
{
    use MeasuresElapsedTime;

    /**
     * Ratings runs first so the cheapest dataset lands even if a later leg dies.
     */
    private const array LEGS = [
        'ratings' => SyncImdbRatings::class,
        'titles' => SyncImdbTitles::class,
        'akas' => SyncImdbAkas::class,
    ];

    public function __construct(private readonly ReindexTouchedRows $reindexTouchedRows)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $arguments = $this->option('force') ? ['--force' => true] : [];

        // Taken before any leg runs so every row a leg touches falls after it.
        $startedAt = now();

        // The suppression is deliberately unobservable: the legs write with raw
        // SQL, so no model event fires and nothing is suppressed today. It stands
        // guard for the day a leg writes through Eloquent, where per-row index
        // pushes would race the one end-of-run reindex below. Not dead code.
        //
        // The flag is *returned* out of both wraps rather than captured with
        // use (&$failed): the outer arrow fn captures by value, so a reference
        // taken here binds to a copy and a failing leg silently exits 0.
        // withoutSyncingToSearch hands back its callback's return value instead.
        $failed = Movie::withoutSyncingToSearch(fn (): bool => Show::withoutSyncingToSearch(
            fn (): bool => $this->runLegs($arguments),
        ));

        $reindexFailed = $this->reindexRowsTouchedSince($startedAt);

        return $failed || $reindexFailed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @param  array<string, bool>  $arguments
     * @return bool whether any leg failed — every leg runs either way
     */
    private function runLegs(array $arguments): bool
    {
        $failed = false;

        foreach (self::LEGS as $dataset => $command) {
            $this->output->writeln("Syncing IMDb {$dataset}…");
            $legStartedAt = microtime(true);

            try {
                if (Artisan::call($command, $arguments, $this->output) !== self::SUCCESS) {
                    $failed = true;
                }
            } catch (Throwable $e) {
                report($e);
                $failed = true;
            }

            $this->output->writeln("  [elapsed {$dataset} {$this->preciseSecondsSince($legStartedAt)}s]");
        }

        return $failed;
    }

    /**
     * Runs on the failure path too: a failed leg must not strand the rows a surviving leg wrote.
     *
     * Each model is guarded on its own for the same reason: a search-engine outage
     * during the movies pass would otherwise strand every show the run touched.
     *
     * @return bool whether either reindex failed — both models run either way
     */
    private function reindexRowsTouchedSince(DateTimeInterface $startedAt): bool
    {
        $write = $this->output->writeln(...);
        $failed = false;

        // Under SCOUT_QUEUE a pass only dispatches the index writes, so its elapsed
        // seconds time the dispatch — say "queued" rather than claim rows were indexed.
        $queued = $this->reindexTouchedRows->queuesIndexing();

        foreach ([Movie::class, Show::class] as $model) {
            $noun = Str::lower(class_basename($model));
            $label = Str::plural($noun);
            $phaseStartedAt = CarbonImmutable::now();

            $write($queued ? "Queueing {$label} for reindex…" : "Reindexing {$label}…");

            try {
                $reindexed = $this->reindexTouchedRows->handle($model, $startedAt, $write);
                $counted = Str::plural($noun, $reindexed);

                $close = $queued
                    ? "Queued {$reindexed} {$counted} for reindex in"
                    : "Reindexed {$reindexed} {$counted} in";
            } catch (Throwable $e) {
                report($e);
                $failed = true;

                // A dead pass still closes: the surviving model prints its own phase
                // line next, and with nothing between them the failure would read as
                // that pass's result instead.
                $close = $queued
                    ? "Queueing {$label} for reindex failed after"
                    : "Reindexing {$label} failed after";
            }

            $write("{$close} {$this->secondsSince($phaseStartedAt)}s");
        }

        return $failed;
    }
}
