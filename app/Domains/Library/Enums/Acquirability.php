<?php

declare(strict_types=1);

namespace App\Domains\Library\Enums;

enum Acquirability: string
{
    case Present = 'present';
    case Acquirable = 'acquirable';
    case Unmatched = 'unmatched';
}
