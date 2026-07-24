<?php

declare(strict_types=1);

namespace App\Domains\Local\Console\Commands;

use App\Domains\Local\Database\MysqlConnection;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;

#[Description('Load the gzipped SQL dumps back into the catalog and download tables, truncating each before its load')]
#[Signature('db:import {--path=} {--from=}')]
final class ImportDatabase extends Command
{
    private const array TABLES = ['movies', 'shows', 'seasons', 'media', 'downloads'];

    public function handle(): int
    {
        $dir = $this->sourceDir();

        if (! File::isDirectory($dir)) {
            $this->error("Source directory does not exist: {$dir}");

            return self::FAILURE;
        }

        // seasons.show_id → shows.id means MySQL refuses TRUNCATE shows while seasons holds rows,
        // so FK checks are lifted across the truncate/load loop.
        Schema::disableForeignKeyConstraints();

        foreach (self::TABLES as $table) {
            $file = $dir.'/'.$table.'.sql.gz';

            if (! File::exists($file)) {
                continue;
            }

            DB::table($table)->truncate();

            // Loading a full dump can run for minutes, past Process' 60s default — run untimed.
            Process::forever()->run("gzip -dc {$file} | mysql ".MysqlConnection::args());
        }

        Schema::enableForeignKeyConstraints();

        return self::SUCCESS;
    }

    private function sourceDir(): string
    {
        $from = $this->option('from');

        if (is_string($from) && $from !== '') {
            return $from;
        }

        $path = $this->option('path');

        return is_string($path) && $path !== '' ? $path : database_path('dumps');
    }
}
