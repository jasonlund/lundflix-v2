<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Throwable;

#[Description('Run the IMDb catalog import commands in order, surviving any single failure')]
#[Signature('sync:catalog')]
class SyncCatalog extends Command
{
    /**
     * The import commands to dispatch, in order.
     *
     * @var list<class-string<Command>>
     */
    private const array COMMANDS = [ImportImdbTitles::class, ImportImdbRatings::class, SyncTmdbMovies::class];

    /**
     * Execute the console command.
     */
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
