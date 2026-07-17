<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Support;

final class RawSourceColumns
{
    /**
     * Map a raw third-party payload onto source-prefixed columns: each key
     * becomes `_{source}_{key}` with the value taken raw (null when absent).
     *
     * @param  list<string>  $keys
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function map(string $source, array $keys, array $payload): array
    {
        $columns = [];

        foreach ($keys as $key) {
            $columns["_{$source}_{$key}"] = $payload[$key] ?? null;
        }

        return $columns;
    }
}
