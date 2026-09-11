<?php

declare(strict_types=1);

namespace App\Domains\Library\Services;

use App\Domains\Catalog\Data\UnitRef;
use App\Domains\Download\Contracts\FindsAcquirableDownloads;
use App\Domains\Library\Data\UnitState;
use App\Domains\Library\Enums\Acquirability;
use App\Domains\Library\Models\Acquisition;
use App\Domains\PlexLibrary\Contracts\ReportsPresence;

final readonly class UnitStateResolver
{
    public function __construct(
        private ReportsPresence $presence,
        private FindsAcquirableDownloads $downloads,
    ) {}

    public function resolve(UnitRef $unit): UnitState
    {
        $status = Acquisition::query()->forUnit($unit)->first()?->status;

        // Presence short-circuits: a unit already on the server needs no download,
        // so the finder is never asked.
        if ($this->presence->has($unit)) {
            return new UnitState(Acquirability::Present, null, $status);
        }

        $downloadId = $this->downloads->for($unit);

        return new UnitState(
            $downloadId === null ? Acquirability::Unmatched : Acquirability::Acquirable,
            $downloadId,
            $status,
        );
    }
}
