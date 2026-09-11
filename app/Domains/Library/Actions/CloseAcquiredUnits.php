<?php

declare(strict_types=1);

namespace App\Domains\Library\Actions;

use App\Domains\Catalog\Data\UnitRef;
use App\Domains\Library\Enums\AcquisitionStatus;
use App\Domains\Library\Models\Acquisition;
use App\Domains\Library\Support\UnitKey;
use App\Domains\PlexLibrary\Contracts\ReportsPresence;
use Illuminate\Support\Collection;

final readonly class CloseAcquiredUnits
{
    private const int CHUNK_SIZE = 1000;

    public function __construct(
        private ReportsPresence $presence,
    ) {}

    public function handle(): int
    {
        $closed = 0;

        // chunkById, not an offset walk: closing flips the very status the query
        // filters on, which would shift offset pages and skip rows mid-walk.
        Acquisition::query()
            ->where('status', AcquisitionStatus::Queued)
            ->chunkById(self::CHUNK_SIZE, function (Collection $acquisitions) use (&$closed): void {
                $present = $this->presence
                    ->present($acquisitions->map(fn (Acquisition $acquisition): UnitRef => $acquisition->unit()))
                    ->keyBy(fn (UnitRef $unit): string => UnitKey::of($unit));

                $landedIds = $acquisitions
                    ->filter(fn (Acquisition $acquisition): bool => $present->has(UnitKey::of($acquisition->unit())))
                    ->modelKeys();

                $closed += Acquisition::query()->whereKey($landedIds)->update(['status' => AcquisitionStatus::Acquired]);
            });

        return $closed;
    }
}
