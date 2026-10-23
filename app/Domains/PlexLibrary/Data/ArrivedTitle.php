<?php

declare(strict_types=1);

namespace App\Domains\PlexLibrary\Data;

use App\Domains\Catalog\Data\UnitRef;

final readonly class ArrivedTitle
{
    /**
     * @param  list<UnitRef>  $units
     */
    public function __construct(
        public string $titleType,
        public int $titleId,
        public string $name,
        public array $units,
    ) {}
}
