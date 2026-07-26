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
            $columns[self::name($source, $key)] = $payload[$key] ?? null;
        }

        return $columns;
    }

    /**
     * The column names {@see map()} writes, in the same order — for callers that
     * need the column list without a payload (e.g. a bulk update's SET clause).
     *
     * @param  list<string>  $keys
     * @return list<string>
     */
    public static function names(string $source, array $keys): array
    {
        return array_map(fn (string $key): string => self::name($source, $key), $keys);
    }

    private static function name(string $source, string $key): string
    {
        return "_{$source}_{$key}";
    }
}
