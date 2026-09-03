<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Data;

final readonly class TitleImportCounts
{
    public function __construct(
        public int $movies,
        public int $shows,
    ) {}
}
