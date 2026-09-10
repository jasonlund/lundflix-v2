<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Data;

use App\Domains\Catalog\Enums\UnitKind;

final readonly class UnitRef
{
    public function __construct(
        public UnitKind $kind,
        public int $id,
    ) {}
}
