<?php

declare(strict_types=1);

namespace App\Domains\PlexLibrary\Data;

use App\Domains\Catalog\Data\UnitRef;
use Carbon\CarbonImmutable;

final readonly class UnitArrival
{
    public function __construct(
        public UnitRef $unit,
        public CarbonImmutable $arrivedAt,
    ) {}
}
