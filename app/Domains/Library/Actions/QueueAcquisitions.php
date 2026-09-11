<?php

declare(strict_types=1);

namespace App\Domains\Library\Actions;

use App\Domains\Catalog\Contracts\Title;
use App\Domains\Catalog\Data\UnitRef;
use App\Domains\Download\Contracts\QueuesDownload;
use App\Domains\Download\Exceptions\DownloadRequestFailed;
use App\Domains\Download\Exceptions\InvalidDownloadCredentials;
use App\Domains\Download\Exceptions\RateLimitExceeded;
use App\Domains\Library\Data\AcquisitionCounts;
use App\Domains\Library\Enums\Acquirability;
use App\Domains\Library\Enums\AcquisitionStatus;
use App\Domains\Library\Enums\Behavior;
use App\Domains\Library\Models\Acquisition;
use App\Domains\Library\Models\Like;
use App\Domains\Library\Services\UnitStateResolver;
use App\Domains\Library\Support\UnitKey;
use Closure;
use Generator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;

final readonly class QueueAcquisitions
{
    public function __construct(
        private UnitStateResolver $resolver,
        private QueuesDownload $downloads,
    ) {}

    /**
     * $onUnitChecked receives the running count of walked units, skipped ones
     * included, so the caller can prove liveness without this action writing output.
     *
     * @param  (Closure(int): void)|null  $onUnitChecked
     */
    public function handle(?Closure $onUnitChecked = null): AcquisitionCounts
    {
        $checked = 0;
        $queued = 0;
        $failed = 0;
        $halted = false;

        foreach ($this->likedUnits() as $unit) {
            $downloadId = $this->pendingDownload($unit);

            $checked++;

            if ($onUnitChecked instanceof Closure) {
                $onUnitChecked($checked);
            }

            if ($downloadId === null) {
                continue;
            }

            // Rejected credentials or a held throttle lock fail every fetch the
            // same way, so the rest of the run is counted as failed rather than
            // hammering the source with requests that cannot succeed.
            if ($halted) {
                $failed++;

                continue;
            }

            // Queue before recording: a record means the download was handed off,
            // so a failed unit is left unrecorded and the next run retries it.
            try {
                $this->downloads->queue($downloadId);
            } catch (DownloadRequestFailed) {
                $failed++;

                continue;
            } catch (InvalidDownloadCredentials|RateLimitExceeded) {
                $halted = true;
                $failed++;

                continue;
            }

            // An overlapping run can record the unit between this run's resolve and
            // here; its record already stands for the fetch, so this unit counts as
            // neither queued nor failed.
            try {
                Acquisition::query()->create([
                    'unit_kind' => $unit->kind,
                    'unit_id' => $unit->id,
                    'download_id' => $downloadId,
                    'status' => AcquisitionStatus::Queued,
                ]);
            } catch (UniqueConstraintViolationException) {
                continue;
            }

            $queued++;
        }

        return new AcquisitionCounts($queued, $failed);
    }

    /**
     * @return Generator<int, UnitRef>
     */
    private function likedUnits(): Generator
    {
        /** @var array<string, true> $seen */
        $seen = [];

        $likes = Like::query()
            ->whereHas('behaviors', fn (Builder $behaviors): Builder => $behaviors
                ->where('behavior', Behavior::Acquire)
                ->whereNotNull('enabled_at'))
            ->with('likeable')
            ->lazy();

        foreach ($likes as $like) {
            $title = $like->likeable;

            // The morph has no foreign key, so a like can outlive its title; there is
            // nothing left to acquire, which is an expected state rather than a failure.
            if (! $title instanceof Title) {
                continue;
            }

            foreach ($title->units() as $unit) {
                // Many users can like one title, but a unit is acquired once. Hashed
                // rather than LazyCollection::unique(), whose in_array scan is
                // quadratic across every liked episode.
                $key = UnitKey::of($unit);

                if (isset($seen[$key])) {
                    continue;
                }

                $seen[$key] = true;

                yield $unit;
            }
        }
    }

    private function pendingDownload(UnitRef $unit): ?int
    {
        $state = $this->resolver->resolve($unit);

        if ($state->acquisition instanceof AcquisitionStatus) {
            return null;
        }

        if ($state->acquirability !== Acquirability::Acquirable) {
            return null;
        }

        return $state->downloadId;
    }
}
