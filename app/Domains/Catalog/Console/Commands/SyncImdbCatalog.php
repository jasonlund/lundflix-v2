<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Console\Commands;

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

    public function handle(): int
    {
        $arguments = $this->option('force') ? ['--force' => true] : [];
        $failed = false;

        foreach (self::LEGS as $dataset => $command) {
            $this->output->writeln("Syncing IMDb {$dataset}…");
            $startedAt = microtime(true);

            try {
                if (Artisan::call($command, $arguments, $this->output) !== self::SUCCESS) {
                    $failed = true;
                }
            } catch (Throwable $e) {
                report($e);
                $failed = true;
            }

            $elapsed = Number::format(microtime(true) - $startedAt, precision: 1);
            $this->output->writeln("  [elapsed {$dataset} {$elapsed}s]");
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
