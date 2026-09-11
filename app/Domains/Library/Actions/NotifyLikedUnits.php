<?php

declare(strict_types=1);

namespace App\Domains\Library\Actions;

use App\Domains\Catalog\Contracts\Title;
use App\Domains\Catalog\Data\UnitRef;
use App\Domains\Catalog\Enums\UnitKind;
use App\Domains\Catalog\Models\Episode;
use App\Domains\Library\Enums\Behavior;
use App\Domains\Library\Models\Like;
use App\Domains\Library\Models\LikeNotification;
use App\Domains\Library\Notifications\LikedUnitsArrived;
use App\Domains\PlexLibrary\Contracts\ReportsArrivals;
use App\Domains\PlexLibrary\Data\UnitArrival;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class NotifyLikedUnits
{
    public function __construct(private ReportsArrivals $arrivals) {}

    /**
     * @param  (Closure(int): void)|null  $onUserNotified  receives the running count of units told, once per user notified
     * @return int the number of units users were told about this run
     */
    public function handle(?Closure $onUserNotified = null): int
    {
        $likes = $this->notifyingLikes();
        $unitsByLike = $this->unitsByLike($likes);
        $arrived = $this->arrivedUnits($unitsByLike->collapse());
        $likesByUser = $likes->groupBy('user_id');
        $told = $this->alreadyTold($likesByUser->keys(), $arrived);

        $total = 0;

        foreach ($likesByUser as $userId => $userLikes) {
            $unseenByLike = $this->unseenByLike($userId, $userLikes, $unitsByLike, $arrived, $told);
            $unseen = $this->distinct($unseenByLike->collapse());

            if ($unseen->isEmpty()) {
                continue;
            }

            $this->deliver($userId, $userLikes, $unseenByLike, $unseen);

            $total += $unseen->count();

            if ($onUserNotified instanceof Closure) {
                $onUserNotified($total);
            }
        }

        return $total;
    }

    /**
     * Materialized rather than streamed: every unit these likes expand to feeds
     * one arrivals() call, so the whole set is in memory regardless.
     *
     * @return Collection<int, Like>
     */
    private function notifyingLikes(): Collection
    {
        return Like::query()
            ->withWhereHas('behaviors', function (Builder|HasMany $behavior): void {
                $behavior->where('behavior', Behavior::Notify)->whereNotNull('enabled_at');
            })
            ->with(['user', 'likeable'])
            ->get(['id', 'user_id', 'likeable_type', 'likeable_id']);
    }

    /**
     * @param  Collection<int, Like>  $likes
     * @return Collection<int, Collection<int, UnitRef>> keyed by like id
     */
    private function unitsByLike(Collection $likes): Collection
    {
        $likedShowIds = $likes->where('likeable_type', 'show')->pluck('likeable_id')->unique()->values();

        $episodesByShow = Episode::query()
            ->whereIn('show_id', $likedShowIds)
            ->toBase()
            ->get(['id', 'show_id'])
            ->groupBy('show_id')
            ->map(fn (Collection $episodes): Collection => $episodes->pluck('id'));

        return $likes->mapWithKeys(fn (Like $like): array => [$like->id => match ($like->likeable_type) {
            'movie' => collect([new UnitRef(UnitKind::Movie, $like->likeable_id)]),
            'show' => $episodesByShow
                ->get($like->likeable_id, collect())
                ->map(fn (int $id): UnitRef => new UnitRef(UnitKind::Episode, $id)),
        }]);
    }

    /**
     * @param  Collection<int, UnitRef>  $units
     * @return Collection<string, UnitArrival> keyed by unitKey()
     */
    private function arrivedUnits(Collection $units): Collection
    {
        return $this->arrivals
            ->arrivals($this->distinct($units))
            ->mapWithKeys(fn (UnitArrival $arrival): array => [$this->unitKey($arrival->unit) => $arrival]);
    }

    /**
     * Only ledger rows for units that arrived can suppress a notice, so the read is
     * bounded by this run's arrivals rather than growing with the ledger forever.
     *
     * @param  Collection<int, int>  $userIds
     * @param  Collection<string, UnitArrival>  $arrived
     * @return Collection<string, true>
     */
    private function alreadyTold(Collection $userIds, Collection $arrived): Collection
    {
        if ($arrived->isEmpty()) {
            return collect();
        }

        return LikeNotification::query()
            ->whereIn('user_id', $userIds)
            ->where(function (Builder $ledger) use ($arrived): void {
                $arrived
                    ->groupBy(fn (UnitArrival $arrival): string => $arrival->unit->kind->value)
                    ->each(function (Collection $arrivals, string $kind) use ($ledger): void {
                        $ledger->orWhere(fn (Builder $bucket): Builder => $bucket
                            ->where('unit_kind', $kind)
                            ->whereIn('unit_id', $arrivals->map(fn (UnitArrival $arrival): int => $arrival->unit->id)));
                    });
            })
            ->get(['user_id', 'unit_kind', 'unit_id'])
            ->mapWithKeys(fn (LikeNotification $row): array => [
                $this->ledgerKey($row->user_id, new UnitRef($row->unit_kind, $row->unit_id)) => true,
            ]);
    }

    /**
     * @param  Collection<int, Like>  $userLikes
     * @param  Collection<int, Collection<int, UnitRef>>  $unitsByLike
     * @param  Collection<string, UnitArrival>  $arrived
     * @param  Collection<string, true>  $told
     * @return Collection<int, Collection<int, UnitRef>> keyed by like id, omitting likes with nothing unseen
     */
    private function unseenByLike(int $userId, Collection $userLikes, Collection $unitsByLike, Collection $arrived, Collection $told): Collection
    {
        return $userLikes
            ->mapWithKeys(fn (Like $like): array => [
                $like->id => $this->arrivedSinceNotifyEnabled($like, $unitsByLike->get($like->id), $arrived)
                    ->reject(fn (UnitRef $unit): bool => $told->has($this->ledgerKey($userId, $unit)))
                    ->values(),
            ])
            ->reject(fn (Collection $units): bool => $units->isEmpty());
    }

    /**
     * Measured from when this like's Notify was last switched on: liking a long-running
     * show must not flood the user with its back catalogue, and re-enabling Notify must
     * not replay what arrived while it was off.
     *
     * @param  Collection<int, UnitRef>  $units
     * @param  Collection<string, UnitArrival>  $arrived
     * @return Collection<int, UnitRef>
     */
    private function arrivedSinceNotifyEnabled(Like $like, Collection $units, Collection $arrived): Collection
    {
        $notifyEnabledAt = $like->behaviors->first()->enabled_at;

        return $units->filter(function (UnitRef $unit) use ($arrived, $notifyEnabledAt): bool {
            $arrival = $arrived->get($this->unitKey($unit));

            return $arrival instanceof UnitArrival && $arrival->arrivedAt->greaterThanOrEqualTo($notifyEnabledAt);
        });
    }

    /**
     * The ledger and the in-app row commit together, so a failed write leaves the
     * units untold for the next run instead of recorded but never delivered.
     *
     * @param  Collection<int, Like>  $userLikes
     * @param  Collection<int, Collection<int, UnitRef>>  $unseenByLike
     * @param  Collection<int, UnitRef>  $unseen
     */
    private function deliver(int $userId, Collection $userLikes, Collection $unseenByLike, Collection $unseen): void
    {
        DB::transaction(function () use ($userId, $userLikes, $unseenByLike, $unseen): void {
            $this->record($userId, $unseen);

            $userLikes->first()->user->notify(new LikedUnitsArrived($unseen->all(), $this->notificationLines($userLikes, $unseenByLike)));
        });
    }

    /**
     * @param  Collection<int, UnitRef>  $units
     */
    private function record(int $userId, Collection $units): void
    {
        $now = now();

        LikeNotification::query()->insert($units->map(fn (UnitRef $unit): array => [
            'user_id' => $userId,
            'unit_kind' => $unit->kind->value,
            'unit_id' => $unit->id,
            'notified_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ])->all());
    }

    /**
     * Stored as plain strings: the payload is a snapshot of what the user was told,
     * never re-resolved against the titles when read.
     *
     * @param  Collection<int, Like>  $userLikes
     * @param  Collection<int, Collection<int, UnitRef>>  $unseenByLike
     * @return list<string>
     */
    private function notificationLines(Collection $userLikes, Collection $unseenByLike): array
    {
        return $unseenByLike
            ->map(fn (Collection $units, int $likeId): string => $this->notificationLine($userLikes->firstWhere('id', $likeId), $units->count()))
            ->values()
            ->all();
    }

    private function notificationLine(Like $like, int $unseenCount): string
    {
        /** @var Title $title */
        $title = $like->likeable;

        return match ($like->likeable_type) {
            'movie' => (string) $title->displayTitle(),
            'show' => $title->displayTitle().': '.$unseenCount.' new '.Str::plural('episode', $unseenCount),
        };
    }

    /**
     * @param  Collection<int, UnitRef>  $units
     * @return Collection<int, UnitRef>
     */
    private function distinct(Collection $units): Collection
    {
        return $units->unique(fn (UnitRef $unit): string => $this->unitKey($unit))->values();
    }

    /**
     * Ids repeat across kinds, so a unit is only identified by both together.
     */
    private function unitKey(UnitRef $unit): string
    {
        return $unit->kind->value.':'.$unit->id;
    }

    private function ledgerKey(int $userId, UnitRef $unit): string
    {
        return $userId.'|'.$this->unitKey($unit);
    }
}
