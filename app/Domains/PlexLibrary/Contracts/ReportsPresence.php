<?php

declare(strict_types=1);

namespace App\Domains\PlexLibrary\Contracts;

use App\Domains\Catalog\Data\UnitRef;
use Illuminate\Support\Collection;

/**
 * Whether the media server already mirrors a unit, so a sweep can skip work it
 * would otherwise do twice.
 */
interface ReportsPresence
{
    public function has(UnitRef $unit): bool;

    /**
     * The subset of $units the server holds — a **filter, not a map**: an absent
     * unit is missing from the result, never returned carrying a false flag, so
     * the result is shorter than the input and cannot be zipped against it.
     * Neither order nor keys are meaningful; identify a returned ref by its kind
     * and id together, since ids repeat across kinds.
     *
     * @param  iterable<UnitRef>  $units
     * @return Collection<int, UnitRef>
     */
    public function present(iterable $units): Collection;
}
