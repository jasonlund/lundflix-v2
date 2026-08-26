<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Console\Commands;

use App\Domains\Catalog\Actions\ReindexTouchedRows;
use App\Domains\Catalog\Models\Movie;
use App\Domains\Catalog\Models\Show;
use DateTimeInterface;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Number;
use Throwable;

#[Description('Sync the IMDb datasets — ratings, titles, akas — in order, surviving any single failure')]
#[Signature('catalog:sync-imdb {--force}')]
class SyncImdbCatalog extends Command
{
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

        $this->reindexRowsTouchedSince($startedAt);

        return $failed ? self::FAILURE : self::SUCCESS;
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

            $elapsed = Number::format(microtime(true) - $legStartedAt, precision: 1);
            $this->output->writeln("  [elapsed {$dataset} {$elapsed}s]");
        }

        return $failed;
    }

    /**
     * Runs on the failure path too: a failed leg must not strand the rows a surviving leg wrote.
     */
    private function reindexRowsTouchedSince(DateTimeInterface $startedAt): void
    {
        $write = $this->output->writeln(...);

        $this->reindexTouchedRows->handle(Movie::class, $startedAt, $write);
        $this->reindexTouchedRows->handle(Show::class, $startedAt, $write);
    }
}
