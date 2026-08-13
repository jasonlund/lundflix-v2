<?php

declare(strict_types=1);

namespace App\Domains\Local\Exceptions;

use Exception;

/**
 * A table's target column order does not name each of its columns exactly once, so no
 * `AFTER` chain can be built. Reordering is all-or-nothing — a partial chain would
 * leave the table in an order nobody asked for, so the caller is told instead.
 */
final class ColumnOrderMismatch extends Exception
{
    /**
     * @param  list<string>  $missing  columns the table has that the target order omits
     * @param  list<string>  $unknown  columns the target order names that the table lacks
     */
    public static function for(string $table, array $missing, array $unknown): self
    {
        return new self(sprintf(
            'The target column order for [%s] is not a permutation of its columns (missing: [%s], unknown: [%s]).',
            $table,
            implode(', ', $missing),
            implode(', ', $unknown),
        ));
    }
}
