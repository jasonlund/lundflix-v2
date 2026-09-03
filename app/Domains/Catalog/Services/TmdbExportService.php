<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Services;

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
     * Download the most-recent daily export named by $export to a temp file,
     * returning its path ($export is the export name, e.g. `movie_ids`).
     *
     * TMDB publishes each day's date-stamped export by 08:00 UTC (the export job
     * starts ~07:00 UTC), so the most-recent published date is today (UTC) once
     * the hour is >= 8, and yesterday (UTC) before that. That single date is
     * requested once — any failure (non-2xx or connection) propagates. The
     * returned temp file is the caller's to consume and delete; it only survives
     * a successful download — a failed attempt unlinks its own temp file before
     * throwing.
     */
    public function download(string $export): string
    {
        $now = now()->utc();
        $date = $now->hour >= 8 ? $now : $now->copy()->subDay();

        return $this->fetch($export, $date->format('m_d_Y'));
    }

    /**
     * Download the export named by $export for one date to a temp file, returning
     * the temp path ($export is the export name, e.g. `movie_ids`).
     *
     * Any failure unlinks the temp file and rethrows.
     */
    private function fetch(string $export, string $date): string
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
                ->get(self::BASE_URL.'/'."{$export}_{$date}.json.gz");

            $response->throw();
        } catch (Throwable $e) {
            @unlink($path);

            throw $e;
        }

        return $path;
    }

    /**
     * Count the decoded JSONL rows that rows() would actually yield.
     *
     * Counts over the SAME decodedRows() generator rows() streams, so the returned
     * total equals the number of rows advanced over downstream — keeping a
     * progress bar's total honest (it reaches 100% exactly, not snapping early).
     * JSONL has no header line, so every non-blank, decodable line is counted.
     */
    public function count(string $path): int
    {
        return iterator_count($this->decodedRows($path));
    }

    /**
     * Stream the decoded JSONL rows of a downloaded export as a lazy collection.
     *
     * Wraps the decodedRows() generator so each non-blank line is JSON-decoded on
     * demand (a partially consumed collection never parses past where it stopped).
     * The underlying gz handle is closed in a finally that runs when the generator
     * completes or is garbage-collected, so callers MUST fully consume the returned
     * collection or the handle leaks until GC.
     */
    public function rows(string $path): LazyCollection
    {
        return LazyCollection::make(fn () => yield from $this->decodedRows($path));
    }

    /**
     * Lazily yield each decoded JSONL row of a downloaded export.
     *
     * The shared read skeleton behind both rows() and count(): open the archive,
     * skip blank lines, JSON-decode on demand, and skip any line that does not
     * decode to an array — so both methods see exactly the same set. The gz handle
     * is closed in a single finally that runs when the generator completes or is
     * garbage-collected, so callers MUST fully consume it or the handle leaks
     * until GC.
     *
     * @return Generator<int, array<string, mixed>>
     */
    private function decodedRows(string $path): Generator
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

                yield $row;
            }
        } finally {
            gzclose($handle);
        }
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
