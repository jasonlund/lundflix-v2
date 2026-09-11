<?php

declare(strict_types=1);

namespace App\Domains\Library\Data;

use App\Domains\Library\Enums\Acquirability;
use App\Domains\Library\Enums\AcquisitionStatus;

final readonly class UnitState
{
    public function __construct(
        public Acquirability $acquirability,
        public ?int $downloadId,
        public ?AcquisitionStatus $acquisition,
    ) {}
}
