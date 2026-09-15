<?php

declare(strict_types=1);

namespace App\Domains\PlexLibrary\Events;

use App\Domains\PlexLibrary\Data\ArrivedTitle;
use Illuminate\Foundation\Events\Dispatchable;

final readonly class UnitsArrived
{
    use Dispatchable;

    /**
     * @param  list<ArrivedTitle>  $titles
     */
    public function __construct(public array $titles) {}
}
