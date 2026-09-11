<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domains\Catalog\Data\UnitRef;
use App\Domains\PlexLibrary\Contracts\ReportsArrivals;
use App\Domains\PlexLibrary\Data\UnitArrival;
use Illuminate\Support\Collection;
use Override;

/**
 * Stands in for the media-server mirror so a test can say when units arrived
 * without arranging mirror rows. It honours the contract's filter: only the
 * arrivals of units the caller asked about come back.
 */
final readonly class FakeArrivals implements ReportsArrivals
{
    /**
     * @param  list<UnitArrival>  $arrivals
     */
    public function __construct(private array $arrivals) {}

    /**
     * @param  iterable<UnitRef>  $units
     * @return Collection<int, UnitArrival>
     */
    #[Override]
    public function arrivals(iterable $units): Collection
    {
        $requested = collect($units);

        // Ids repeat across kinds, so a unit matches on its kind and id together.
        return collect($this->arrivals)
            ->filter(fn (UnitArrival $arrival): bool => $requested->contains(
                fn (UnitRef $unit): bool => $unit->kind === $arrival->unit->kind && $unit->id === $arrival->unit->id,
            ))
            ->values();
    }
}
