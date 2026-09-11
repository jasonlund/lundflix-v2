<?php

declare(strict_types=1);

namespace App\Domains\PlexLibrary\Contracts;

use App\Domains\Catalog\Data\UnitRef;
use App\Domains\PlexLibrary\Data\UnitArrival;
use Illuminate\Support\Collection;

/**
 * When the media server took in a unit, so a caller can tell a unit that
 * arrived after some moment of interest from one that was already there.
 */
interface ReportsArrivals
{
    /**
     * One arrival per unit the server mirrors with a known arrival time — a
     * **filter, not a map**: an unmirrored unit, or one whose arrival time the
     * server never reported, is missing from the result rather than returned
     * with a null time. Neither order nor keys are meaningful; identify a
     * returned arrival's unit by its kind and id together, since ids repeat
     * across kinds.
     *
     * @param  iterable<UnitRef>  $units
     * @return Collection<int, UnitArrival>
     */
    public function arrivals(iterable $units): Collection;
}
