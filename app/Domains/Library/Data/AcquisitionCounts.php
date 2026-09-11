<?php

declare(strict_types=1);

namespace App\Domains\Library\Data;

final readonly class AcquisitionCounts
{
    public function __construct(
        public int $queued,
        public int $failed,
    ) {}
}
