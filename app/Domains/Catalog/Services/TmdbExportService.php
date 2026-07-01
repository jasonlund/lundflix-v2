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
use Throwable;

final class TmdbExportService
{
    private const string BASE_URL = 'https://files.tmdb.org/p/exports';

    /**
     * Download today's daily export for the given kind to a temp file, returning
     * its path; the kind defaults to the movie-ids export.
     *
     * TMDB publishes the export under a date-stamped URL and the current day's
     * file may not exist yet, so a 404 on today falls back to the prior day; any
     * other failure (or a 404 on the fallback) propagates. The returned temp file
     * is the caller's to consume and delete; it only survives a successful
     * download — a failed attempt unlinks its own temp file before throwing.
     */
    public function download(TmdbExport $kind = TmdbExport::MovieIds): string
    {
        $today = $this->attempt($kind, now()->format('m_d_Y'), allow404: true);

        if ($today !== null) {
            return $today;
        }

        return $this->attempt($kind, now()->subDay()->format('m_d_Y'), allow404: false);
    }

    /**
     * Download the export for one date to a temp file.
     *
     * Returns the temp path on success. When $allow404 is true a 404 is treated
     * as "not published yet": the temp file is removed and null returned so the
     * caller can fall back. Any other failure unlinks the temp file and rethrows.
     */
    private function attempt(TmdbExport $kind, string $date, bool $allow404): ?string
    {
        $path = tempnam(sys_get_temp_dir(), 'tmdb_');

        if ($path === false) {
            throw CannotCreateTmdbTempFile::inTempDir(sys_get_temp_dir());
        }

        try {
            $response = Http::sink($path)
                ->timeout(600)
                ->retry(3, 1000, throw: false)
                ->get(self::BASE_URL.'/'.$kind->filename($date));

            if ($allow404 && $response->status() === 404) {
                @unlink($path);

                return null;
            }

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
                if (rtrim($line, "\r\n") === '') {
                    continue;
                }

                $row = json_decode(trim($line), true);

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
