<?php

namespace App\Domains\Catalog\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Throwable;

class SyncCatalog extends Command
{
    /**
     * The import commands to dispatch, in order.
     *
     * @var list<class-string<Command>>
     */
    private const COMMANDS = [ImportImdbTitles::class, ImportImdbRatings::class];

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sync:catalog';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Run the IMDb catalog import commands in order, surviving any single failure';

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
