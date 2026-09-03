<?php

declare(strict_types=1);

namespace App\Domains\Local\Console\Commands;

use App\Domains\Local\Database\DumpFit;
use App\Domains\Local\Database\DumpSelection;
use App\Domains\Local\Database\MysqlConnection;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

#[Description('Dump the catalog and download tables to gzipped SQL, capping the version-controlled set under a size budget')]
#[Signature('db:dump {--unlimited} {--vc} {--full} {--path=}')]
final class DumpDatabase extends Command
{
    /**
     * Per-file budget for the version-controlled set, held under GitHub's 50 MiB
     * warning line. The 512 KiB margin absorbs run-to-run drift: the fit measures
     * one `mysqldump | gzip`, the final dump is a separate one, so the byte count
     * can wobble a little — the margin keeps the committed file from tipping over.
     */
    private const int CAP_BYTES = 50 * 1024 * 1024 - 512 * 1024;

    private const array TABLES = ['movies', 'shows', 'seasons', 'media', 'downloads'];

    /**
     * mysqldump connection args (`-h -P -u <db>`), resolved once from the mysql
     * connection config and reused across every table and measurement.
     */
    private ?string $connectionArgs = null;

    public function handle(): int
    {
        $writeVc = ! $this->option('full') || $this->option('vc');
        $writeFull = ! $this->option('vc') || $this->option('full');

        if ($writeVc) {
            $this->dumpSet(database_path('dumps'), capped: ! $this->option('unlimited'));
        }

        if ($writeFull) {
            $this->dumpSet($this->fullDir(), capped: false);
        }

        return self::SUCCESS;
    }

    private function fullDir(): string
    {
        $path = $this->option('path');

        return is_string($path) && $path !== '' ? $path : (string) config('database.dump_path');
    }

    /**
     * A capped set seeds the best-first prefix of each table under the budget:
     * movies/shows by popularity, media kept coherent to those included titles
     * (never orphaned), and downloads independently by availability — their
     * title links are optional and frequently unset, so they stand on their own.
     */
    private function dumpSet(string $dir, bool $capped): void
    {
        File::ensureDirectoryExists($dir);

        if (! $capped) {
            foreach (self::TABLES as $table) {
                $this->dumpTable($table, null, "{$dir}/{$table}.sql.gz");
            }

            return;
        }

        $movieLimit = $this->fitAndDump('movies', static fn (int $n): string => self::orderedWhere('_tmdb_popularity', $n), "{$dir}/movies.sql.gz");
        $showLimit = $this->fitAndDump('shows', static fn (int $n): string => self::orderedWhere('_tmdb_popularity', $n), "{$dir}/shows.sql.gz");

        $seasonMembership = DumpSelection::seasonsWhere($showLimit);
        $this->fitAndDump(
            'seasons',
            static fn (int $n): string => "({$seasonMembership}) ORDER BY id LIMIT {$n}",
            "{$dir}/seasons.sql.gz",
            DB::table('seasons')->whereRaw($seasonMembership)->count(),
        );

        $membership = DumpSelection::mediaWhere($movieLimit, $showLimit);
        $this->fitAndDump(
            'media',
            static fn (int $n): string => "({$membership}) ORDER BY id LIMIT {$n}",
            "{$dir}/media.sql.gz",
            DB::table('media')->whereRaw($membership)->count(),
        );

        $this->fitAndDump('downloads', static fn (int $n): string => self::orderedWhere('_provider_availability', $n), "{$dir}/downloads.sql.gz");
    }

    /**
     * Fit $table to its largest best-first prefix under the budget, then dump it.
     *
     * $whereFor maps a row count to the smuggled ORDER BY … LIMIT filter mysqldump
     * (which has no order/limit flags) must carry to select that prefix. Returns the
     * fitted row count so children can be bounded to the same selection.
     *
     * @param  callable(int): string  $whereFor
     */
    private function fitAndDump(string $table, callable $whereFor, string $dest, ?int $total = null): int
    {
        $total ??= DB::table($table)->count();

        $n = DumpFit::largestUnderCap(
            $total,
            self::CAP_BYTES,
            fn (int $n): int => $this->measure($table, $whereFor($n)),
        );

        $this->dumpTable($table, $whereFor($n), $dest);

        return $n;
    }

    /**
     * mysqldump has no order/limit flags, so the best-first prefix is smuggled
     * through the row filter: a tautology plus an ORDER BY … LIMIT tail. The `id`
     * tiebreak makes the prefix deterministic across the parent/child queries.
     */
    private static function orderedWhere(string $column, int $n): string
    {
        return "1=1 ORDER BY {$column} DESC, id DESC LIMIT {$n}";
    }

    private function dumpTable(string $table, ?string $where, string $dest): void
    {
        // Write to a temp sibling first, then atomically rename onto $dest only after
        // the pipeline succeeds — so a failed mysqldump never clobbers the committed
        // dump with a corrupt/empty file.
        $temp = $dest.'.tmp';

        $result = $this->runProcess($this->pipefail(
            "{$this->mysqldumpCommand($table, $where)} | gzip -c > ".escapeshellarg($temp),
        ));

        if (! $result->successful()) {
            File::delete($temp);
            $this->fail("mysqldump failed for [{$table}]: ".Str::trim($result->errorOutput()));
        }

        // On the fake Process facade (tests) no temp file is produced; guard the move
        // so the success assertion still holds without a real dump on disk.
        if (File::exists($temp)) {
            File::move($temp, $dest);
        }
    }

    /**
     * Real gzipped byte size of the dump, which {@see fitAndDump} compares against the cap.
     */
    private function measure(string $table, ?string $where): int
    {
        $result = $this->runProcess($this->pipefail(
            "{$this->mysqldumpCommand($table, $where)} | gzip -c | wc -c",
        ));

        if (! $result->successful()) {
            $this->fail("mysqldump failed while measuring [{$table}]: ".Str::trim($result->errorOutput()));
        }

        $bytes = Str::trim($result->output());

        return $bytes === '' ? 0 : (int) $bytes;
    }

    /**
     * Wrap a shell pipeline in `bash -o pipefail -c` so an upstream mysqldump failure
     * fails the whole pipeline. Without pipefail the exit status is the last stage's
     * (gzip/wc) alone, masking a broken dump behind a healthy compressor.
     */
    private function pipefail(string $pipeline): string
    {
        return 'bash -o pipefail -c '.escapeshellarg($pipeline);
    }

    /**
     * Dumping a large table can run for minutes, well past Process' 60s default —
     * so these shell-outs run untimed.
     */
    private function runProcess(string $command): ProcessResult
    {
        return Process::forever()->env(MysqlConnection::passwordEnv())->run($command);
    }

    private function mysqldumpCommand(string $table, ?string $where): string
    {
        // --single-transaction: snapshot without LOCK TABLES, so a child --where may
        //   read its parent tables in a subquery (a locked dump can only see its own
        //   table and would match zero rows).
        // --set-gtid-purged=OFF: omit the SET @@GLOBAL.GTID_PURGED header, which
        //   would fail on import into a fresh (non-empty-GTID) server.
        $command = "mysqldump {$this->connectionArgs()} --single-transaction --set-gtid-purged=OFF {$table}";

        if ($where !== null) {
            $command .= ' --where='.escapeshellarg($where);
        }

        return "{$command} --no-create-info --complete-insert";
    }

    private function connectionArgs(): string
    {
        return $this->connectionArgs ??= MysqlConnection::args();
    }
}
