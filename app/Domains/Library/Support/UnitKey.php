<?php

declare(strict_types=1);

namespace App\Domains\Library\Support;

use App\Domains\Catalog\Data\UnitRef;

/**
 * A unit's identity by value, for hashing: every read builds fresh UnitRef
 * objects, and ids repeat across kinds, so neither object identity nor the id
 * alone names one unit.
 */
final readonly class UnitKey
{
    public static function of(UnitRef $unit): string
    {
        return $unit->kind->value.':'.$unit->id;
    }
}
