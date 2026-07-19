<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Support;

use Illuminate\Support\Str;
use Stringable;

final class SourceId
{
    /**
     * Validate an IMDb title crosswalk id (`tt\d+`), trimmed; malformed to null.
     */
    public static function imdb(mixed $raw): ?string
    {
        if (! is_string($raw) && ! $raw instanceof Stringable) {
            return null;
        }

        $trimmed = Str::trim((string) $raw);

        return preg_match('/^tt\d+$/', $trimmed) ? $trimmed : null;
    }

    /**
     * Validate a TMDB numeric id: digits only, in-range for the column; else null.
     */
    public static function tmdb(mixed $raw): ?int
    {
        if (! is_string($raw) && ! is_int($raw) && ! $raw instanceof Stringable) {
            return null;
        }

        $string = Str::trim((string) $raw);

        // ctype_digit gates the cast: it rejects signs, decimals, and appended
        // junk that (int) would silently truncate (e.g. "1335814-silvio-santos").
        if (! ctype_digit($string)) {
            return null;
        }

        $number = (int) $string;

        // 4_294_967_295 is the `int unsigned` column max; ids past it are
        // overflowed/corrupt, not real TMDB ids.
        return ($number > 0 && $number <= 4_294_967_295) ? $number : null;
    }

    /**
     * Coerce a positive integer (or positive-integer numeric string) for an
     * upsert key; non-positive or non-integer input to null.
     */
    public static function positiveInt(mixed $raw): ?int
    {
        if (is_int($raw)) {
            return $raw > 0 ? $raw : null;
        }

        if (is_string($raw) && ctype_digit($raw) && (int) $raw > 0) {
            return (int) $raw;
        }

        return null;
    }
}
