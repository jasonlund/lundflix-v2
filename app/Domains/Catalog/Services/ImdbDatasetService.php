<?php

namespace App\Domains\Catalog\Services;

use App\Domains\Catalog\Enums\ImdbDataset;
use App\Domains\Catalog\Exceptions\CannotOpenImdbDatasetArchive;
use App\Domains\Catalog\Exceptions\CorruptImdbDatasetArchive;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\LazyCollection;

final class ImdbDatasetService
{
    public function download(ImdbDataset $dataset): string
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

    public function count(string $path): int
    {
        $handle = $this->open($path);

        try {
            // Discard the TSV header row before counting data rows.
            gzgets($handle);

            $count = 0;

            while (($line = gzgets($handle)) !== false) {
                if (rtrim($line, "\r\n") !== '') {
                    $count++;
                }
            }

            return $count;
        } finally {
            gzclose($handle);
        }
    }

    public function rows(string $path, ImdbDataset $dataset): LazyCollection
    {
        return LazyCollection::make(function () use ($path, $dataset) {
            $handle = $this->open($path);

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

                    unset($raw['isAdult']);

                    yield $this->cast($raw, $dataset->casts());
                }
            } finally {
                gzclose($handle);
            }
        });
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
            fn ($value) => $value === '\N' ? null : $value,
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

    private function open(string $path)
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
