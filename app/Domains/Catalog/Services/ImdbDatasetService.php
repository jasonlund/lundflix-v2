<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Services;

use App\Domains\Catalog\Enums\ImdbDataset;
use App\Domains\Catalog\Exceptions\CannotOpenImdbDatasetArchive;
use App\Domains\Catalog\Exceptions\CorruptImdbDatasetArchive;
use Closure;
use Generator;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\LazyCollection;
use Illuminate\Support\Str;
use Throwable;

final class ImdbDatasetService
{
    private const string BASE_URL = 'https://datasets.imdbws.com';

    public function download(ImdbDataset $dataset): string
    {
        $path = tempnam(sys_get_temp_dir(), 'imdb_');

        try {
            Http::sink($path)
                ->timeout(600)
                ->withOptions(['retry_enabled' => false])
                ->retry(3, 1000)
                ->get($this->url($dataset))
                ->throw();
        } catch (Throwable $e) {
            @unlink($path);

            throw $e;
        }

        return $path;
    }

    public function lastModified(ImdbDataset $dataset): ?string
    {
        try {
            $response = Http::head($this->url($dataset));
        } catch (Throwable) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $lastModified = $response->header('Last-Modified');

        return $lastModified === '' ? null : $lastModified;
    }

    /**
     * Count the data rows that rows() would actually yield.
     *
     * Skips the header and every blank line, so the total equals the number of
     * rows rows() advances over downstream — keeping a progress bar's total
     * honest (it reaches 100% exactly, not ~72% then snap).
     */
    public function count(string $path): int
    {
        $handle = $this->open($path);

        try {
            $this->readHeader($handle, $path);

            return iterator_count($this->dataLines($handle));
        } finally {
            gzclose($handle);
        }
    }

    /**
     * Stream the kept, casted data rows as a lazy collection.
     *
     * IMPORTANT: the underlying gz handle is closed in a finally that only runs
     * when the generator completes or is garbage-collected. Callers MUST fully
     * consume the returned collection (e.g. ->all(), or a foreach to the end);
     * abandoning it part-way leaves the gz handle open until GC reclaims it.
     */
    public function rows(string $path, ImdbDataset $dataset): LazyCollection
    {
        return LazyCollection::make(function () use ($path, $dataset) {
            $handle = $this->open($path);

            try {
                $header = $this->fields($this->readHeader($handle, $path));
                $casts = $dataset->casts();

                foreach ($this->dataLines($handle) as $line) {
                    yield $this->cast($this->mapRow($header, $this->fields($line)), $casts);
                }
            } finally {
                gzclose($handle);
            }
        });
    }

    /**
     * Stream only the catalog-matched rows of a dataset, fully parsed, in file order.
     *
     * Each data line surrenders only its first tab-delimited field (the id) until its
     * probe batch resolves; unmatched lines are discarded without column mapping or
     * casting. A probe batch closes at an id change once $batchSize distinct ids are
     * buffered — never mid-run of one id — plus a final tail batch.
     *
     * IMPORTANT: like rows(), this holds an open gz handle for the life of the
     * generator, so callers MUST consume the collection to the end.
     *
     * @param  Closure(list<string>): array<string, true>  $existing  batched catalog probe
     * @param  Closure(int): void|null  $scanned  cumulative scanned data-row count, called once per probe batch (tail included)
     * @return LazyCollection<int, array<string, mixed>> rows cast identically to rows()
     */
    public function matchedRows(string $path, ImdbDataset $dataset, Closure $existing, int $batchSize, ?Closure $scanned = null): LazyCollection
    {
        return LazyCollection::make(function () use ($path, $dataset, $existing, $batchSize, $scanned) {
            $handle = $this->open($path);

            try {
                $header = $this->fields($this->readHeader($handle, $path));
                $casts = $dataset->casts();

                /** @var list<array{0: string, 1: string}> $bufferedLines */
                $bufferedLines = [];
                /** @var list<string> $bufferedIds */
                $bufferedIds = [];
                $lastId = null;
                $scannedRows = 0;

                foreach ($this->dataLines($handle) as $line) {
                    $id = Str::rtrim(explode("\t", $line, 2)[0], "\r\n");
                    $startsNewId = $id !== $lastId;

                    // Only an id change may close a batch: one id spans dozens of
                    // consecutive akas rows, and splitting that run would probe the
                    // same id twice and yield its rows out of file order.
                    if ($startsNewId && count($bufferedIds) >= $batchSize) {
                        yield from $this->admittedRows($bufferedLines, $bufferedIds, $header, $casts, $existing);

                        $bufferedLines = [];
                        $bufferedIds = [];

                        if ($scanned instanceof Closure) {
                            $scanned($scannedRows);
                        }
                    }

                    if ($startsNewId) {
                        $bufferedIds[] = $id;
                        $lastId = $id;
                    }

                    $bufferedLines[] = [$id, $line];
                    $scannedRows++;
                }

                if ($bufferedLines !== []) {
                    yield from $this->admittedRows($bufferedLines, $bufferedIds, $header, $casts, $existing);

                    if ($scanned instanceof Closure) {
                        $scanned($scannedRows);
                    }
                }
            } finally {
                gzclose($handle);
            }
        });
    }

    /**
     * Resolve one probe batch and yield its admitted lines, parsed, in file order.
     *
     * A buffered line is split into columns and cast only once the probe admits its
     * id, so an unmatched line — including a malformed one short a column — never
     * reaches mapRow().
     *
     * @param  list<array{0: string, 1: string}>  $bufferedLines  id/raw-line pairs, in file order
     * @param  list<string>  $ids
     * @param  list<string>  $header
     * @param  array<string, string>  $casts
     * @param  Closure(list<string>): array<string, true>  $existing
     * @return Generator<int, array<string, mixed>>
     */
    private function admittedRows(array $bufferedLines, array $ids, array $header, array $casts, Closure $existing): Generator
    {
        $matches = $existing($ids);

        foreach ($bufferedLines as [$id, $line]) {
            if (! isset($matches[$id])) {
                continue;
            }

            yield $this->cast($this->mapRow($header, $this->fields($line)), $casts);
        }
    }

    private function url(ImdbDataset $dataset): string
    {
        return self::BASE_URL.'/'.$dataset->filename();
    }

    /**
     * Read and return the raw TSV header line, guarding against an empty body.
     *
     * A gzip with valid magic but no content yields false on the first gzgets;
     * passing that to array_combine would raise an opaque ValueError, so we
     * surface the domain exception instead.
     *
     * @param  resource  $handle
     */
    private function readHeader($handle, string $path): string
    {
        $header = gzgets($handle);

        if ($header === false) {
            throw CorruptImdbDatasetArchive::at($path);
        }

        return $header;
    }

    /**
     * Stream the raw data lines of an open, past-the-header archive.
     *
     * Blank lines are skipped rather than yielded, so what count() totals and what
     * rows()/matchedRows() walk are the same set of lines.
     *
     * @param  resource  $handle
     * @return Generator<int, string>
     */
    private function dataLines($handle): Generator
    {
        while (($line = gzgets($handle)) !== false) {
            if (Str::rtrim($line, "\r\n") === '') {
                continue;
            }

            yield $line;
        }
    }

    /**
     * @return list<string>
     */
    private function fields(string $line): array
    {
        return explode("\t", Str::rtrim($line, "\r\n"));
    }

    /**
     * @param  list<string>  $header
     * @param  list<string>  $fields
     * @return array<string, string|null>
     */
    private function mapRow(array $header, array $fields): array
    {
        return array_map(
            fn (string $value): ?string => $value === '\N' ? null : $value,
            array_combine($header, $fields)
        );
    }

    /**
     * @param  array<string, string|null>  $row
     * @param  array<string, string>  $casts
     * @return array<string, mixed>
     */
    private function cast(array $row, array $casts): array
    {
        foreach ($casts as $column => $type) {
            if (! array_key_exists($column, $row) || $row[$column] === null) {
                continue;
            }

            $value = $row[$column];

            $row[$column] = match ($type) {
                'int' => (int) $value,
                'float' => (float) $value,
                'bool' => $value === '1',
                'array' => explode(',', $value),
                // IMDb packs repeated values inside one akas column with \x02,
                // not the comma it uses elsewhere.
                'multi' => explode(chr(2), $value),
                default => $value,
            };
        }

        return $row;
    }

    /**
     * @return resource
     */
    private function open(string $path): mixed
    {
        if (! $this->isGzip($path)) {
            throw CorruptImdbDatasetArchive::at($path);
        }

        $handle = gzopen($path, 'rb');

        if ($handle === false) {
            throw CannotOpenImdbDatasetArchive::at($path);
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
