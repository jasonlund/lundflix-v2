<?php

namespace App\Domains\Catalog\Services;

use App\Domains\Catalog\Enums\ImdbDataset;
use App\Domains\Catalog\Exceptions\CannotOpenImdbDatasetArchive;
use App\Domains\Catalog\Exceptions\CorruptImdbDatasetArchive;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\LazyCollection;

final class ImdbDatasetService
{
    public function rows(ImdbDataset $dataset): LazyCollection
    {
        return LazyCollection::make(function () use ($dataset) {
            $path = $this->download($dataset);

            yield from $this->parse($dataset, $path);
        });
    }

    /**
     * Stream the gzipped TSV at $path, yielding each kept-and-cast row.
     *
     * @return \Generator<int, array<string, mixed>>
     */
    private function parse(ImdbDataset $dataset, string $path): \Generator
    {
        try {
            if (! $this->isGzip($path)) {
                throw CorruptImdbDatasetArchive::at($path);
            }

            $handle = gzopen($path, 'rb');

            if ($handle === false) {
                throw CannotOpenImdbDatasetArchive::at($path);
            }

            try {
                $header = $this->fields(gzgets($handle));

                while (($line = gzgets($handle)) !== false) {
                    if (rtrim($line, "\r\n") === '') {
                        continue;
                    }

                    $raw = $this->mapRow($header, $this->fields($line));

                    if (! $dataset->includes($raw)) {
                        continue;
                    }

                    yield $this->cast($raw, $dataset->casts());
                }
            } finally {
                gzclose($handle);
            }
        } finally {
            @unlink($path);
        }
    }

    /**
     * Determine whether the file at $path begins with the gzip magic bytes.
     */
    private function isGzip(string $path): bool
    {
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            return false;
        }

        try {
            $magic = fread($handle, 2);
        } finally {
            fclose($handle);
        }

        return $magic === "\x1f\x8b";
    }

    private function download(ImdbDataset $dataset): string
    {
        $path = tempnam(sys_get_temp_dir(), 'imdb_');

        try {
            Http::sink($path)
                ->timeout(600)
                ->retry(3, 1000)
                ->get(config('services.imdb.base_url').'/'.$dataset->filename())
                ->throw();
        } catch (\Throwable $e) {
            @unlink($path);

            throw $e;
        }

        return $path;
    }

    /**
     * @return array<int, string>
     */
    private function fields(string $line): array
    {
        return explode("\t", rtrim($line, "\r\n"));
    }

    /**
     * @param  array<int, string>  $header
     * @param  array<int, string>  $fields
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
        foreach ($casts as $col => $type) {
            if (! array_key_exists($col, $row) || $row[$col] === null) {
                continue;
            }

            $value = $row[$col];

            $row[$col] = match ($type) {
                'int' => (int) $value,
                'float' => (float) $value,
                'bool' => $value === '1',
                'array' => explode(',', $value),
                default => $value,
            };
        }

        return $row;
    }
}
