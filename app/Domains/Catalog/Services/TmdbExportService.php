<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Services;

use App\Domains\Catalog\Enums\TmdbExport;
use App\Domains\Catalog\Exceptions\CannotCreateTmdbTempFile;
use App\Domains\Catalog\Exceptions\CannotOpenTmdbExportArchive;
use App\Domains\Catalog\Exceptions\CorruptTmdbExportArchive;
use Generator;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\LazyCollection;
use Illuminate\Support\Str;
use Throwable;

final class TmdbExportService
{
    private const string BASE_URL = 'https://files.tmdb.org/p/exports';

    /**
     * Download the most-recent daily export for the given kind to a temp file,
     * returning its path; the kind defaults to the movie-ids export.
     *
     * TMDB publishes each day's date-stamped export by 08:00 UTC (the export job
     * starts ~07:00 UTC), so the most-recent published date is today (UTC) once
     * the hour is >= 8, and yesterday (UTC) before that. That single date is
     * requested once — any failure (non-2xx or connection) propagates. The
     * returned temp file is the caller's to consume and delete; it only survives
     * a successful download — a failed attempt unlinks its own temp file before
     * throwing.
     */
    public function download(TmdbExport $kind = TmdbExport::MovieIds): string
    {
        $now = now()->utc();
        $date = $now->hour >= 8 ? $now : $now->copy()->subDay();

        return $this->fetch($kind, $date->format('m_d_Y'));
    }

    /**
     * Download the export for one date to a temp file, returning the temp path.
     *
     * Any failure unlinks the temp file and rethrows.
     */
    private function fetch(TmdbExport $kind, string $date): string
    {
        $path = tempnam(sys_get_temp_dir(), 'tmdb_');

        if ($path === false) {
            throw CannotCreateTmdbTempFile::inTempDir(sys_get_temp_dir());
        }

        try {
            $response = Http::sink($path)
                ->timeout(600)
                ->withOptions(['retry_enabled' => false])
                ->retry(3, 1000, throw: false)
                ->get(self::BASE_URL.'/'.$kind->filename($date));

            $response->throw();
        } catch (Throwable $e) {
            @unlink($path);

            throw $e;
        }

        return $path;
    }

    /**
     * Count the kept JSONL rows that rows() would actually yield.
     *
     * Counts over the SAME keptRows() generator rows() streams, so the returned
     * total equals the number of rows advanced over downstream — keeping a
     * progress bar's total honest (it reaches 100% exactly, not snapping early).
     * JSONL has no header line, so every non-blank, non-excluded line is counted.
     */
    public function count(string $path): int
    {
        return iterator_count($this->keptRows($path));
    }

    /**
     * Stream the kept, decoded JSONL rows of a downloaded export as a lazy collection.
     *
     * Wraps the keptRows() generator so each non-blank line is JSON-decoded on
     * demand (a partially consumed collection never parses past where it stopped)
     * and adult/softcore rows are dropped. The underlying gz handle is closed in a
     * finally that runs when the generator completes or is garbage-collected, so
     * callers MUST fully consume the returned collection or the handle leaks until GC.
     */
    public function rows(string $path): LazyCollection
    {
        return LazyCollection::make(fn () => yield from $this->keptRows($path));
    }

    /**
     * Lazily yield each kept, decoded JSONL row of a downloaded export.
     *
     * The shared read skeleton behind both rows() and count(): open the archive,
     * skip blank lines, JSON-decode on demand, and drop adult/softcore rows via
     * isExcluded() — so both methods see exactly the same kept set. The gz handle
     * is closed in a single finally that runs when the generator completes or is
     * garbage-collected, so callers MUST fully consume it or the handle leaks
     * until GC.
     *
     * @return Generator<int, array<string, mixed>>
     */
    private function keptRows(string $path): Generator
    {
        $handle = $this->open($path);

        try {
            while (($line = gzgets($handle)) !== false) {
                if (Str::rtrim($line, "\r\n") === '') {
                    continue;
                }

                $row = json_decode(Str::trim($line), true);

                if (! is_array($row)) {
                    continue;
                }

                if ($this->isExcluded($row)) {
                    continue;
                }

                yield $row;
            }
        } finally {
            gzclose($handle);
        }
    }

    /**
     * Whether a decoded row is adult or softcore and must be dropped.
     *
     * @param  array<string, mixed>  $row
     */
    private function isExcluded(array $row): bool
    {
        return ($row['adult'] ?? false) === true || ($row['softcore'] ?? false) === true;
    }

    /**
     * @return resource
     */
    private function open(string $path): mixed
    {
        if (! $this->isGzip($path)) {
            throw CorruptTmdbExportArchive::at($path);
        }

        $handle = gzopen($path, 'rb');

        if ($handle === false) {
            throw CannotOpenTmdbExportArchive::at($path);
        }

        return $handle;
    }

    private function isGzip(string $path): bool
    {
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            return false;
        }

        try {
            $magic = fread($handle, 2);

            return $magic === "\x1f\x8b";
        } finally {
            fclose($handle);
        }
    }
}
