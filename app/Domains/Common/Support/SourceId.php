<?php

declare(strict_types=1);

namespace App\Domains\Common\Support;

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
        // 4_294_967_295 is the `int unsigned` column max (same cap as tmdb()); a
        // value past it is overflow/corruption, not a real upsert key.
        if (is_int($raw)) {
            return ($raw > 0 && $raw <= 4_294_967_295) ? $raw : null;
        }

        if (! is_string($raw) || ! ctype_digit($raw)) {
            return null;
        }

        // Range-check as a string BEFORE the (int) cast so a digit-only string
        // past PHP_INT_MAX can't truncate into a valid-looking key first.
        if (Str::length($raw) > 10 || (int) $raw > 4_294_967_295) {
            return null;
        }

        $number = (int) $raw;

        return $number > 0 ? $number : null;
    }
}
