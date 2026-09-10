<?php

declare(strict_types=1);

namespace App\Domains\Library\Enums;

enum Behavior: string
{
    case Acquire = 'acquire';
    case Notify = 'notify';
}
