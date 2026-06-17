<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Services;

use App\Domains\Catalog\Enums\ImdbDataset;
use App\Domains\Catalog\Exceptions\CannotOpenImdbDatasetArchive;
use App\Domains\Catalog\Exceptions\CorruptImdbDatasetArchive;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\LazyCollection;
use Throwable;

final class ImdbDatasetService
{
    private const BASE_URL = 'https://datasets.imdbws.com';

    public function download(ImdbDataset $dataset): string
    {
        $path = tempnam(sys_get_temp_dir(), 'imdb_');

        try {
            Http::sink($path)
                ->timeout(600)
                ->retry(3, 1000)
                ->get(self::BASE_URL.'/'.$dataset->filename())
                ->throw();
        } catch (Throwable $e) {
            @unlink($path);

            throw $e;
        }

        return $path;
    }

    /**
     * Count the data rows that rows() would actually yield.
     *
     * Applies the SAME includes() filter as rows() so the returned total equals
     * the number of rows that will be advanced over downstream — keeping a
     * progress bar's total honest (it reaches 100% exactly, not ~72% then snap).
     * A dataset whose includes() always returns true counts every non-blank row.
     */
    public function count(string $path, ?ImdbDataset $dataset = null): int
    {
        $handle = $this->open($path);

        try {
            $header = $this->fields($this->readHeader($handle, $path));

            $count = 0;

            while (($line = gzgets($handle)) !== false) {
                if (rtrim($line, "\r\n") === '') {
                    continue;
                }

                if ($dataset !== null && ! $dataset->includes($this->mapRow($header, $this->fields($line)))) {
                    continue;
                }

                $count++;
            }

            return $count;
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

                while (($line = gzgets($handle)) !== false) {
                    if (rtrim($line, "\r\n") === '') {
                        continue;
                    }

                    $raw = $this->mapRow($header, $this->fields($line));

                    if (! $dataset->includes($raw)) {
                        continue;
                    }

                    unset($raw['isAdult']);

                    yield $this->cast($raw, $dataset->casts());
                }
            } finally {
                gzclose($handle);
            }
        });
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
     * @return list<string>
     */
    private function fields(string $line): array
    {
        return explode("\t", rtrim($line, "\r\n"));
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
