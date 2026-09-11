<?php

declare(strict_types=1);

namespace App\Domains\Library\Enums;

enum AcquisitionStatus: string
{
    case Queued = 'queued';
    case Acquired = 'acquired';
}
