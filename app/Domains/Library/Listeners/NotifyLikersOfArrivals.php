<?php

declare(strict_types=1);

namespace App\Domains\Library\Listeners;

use App\Domains\Catalog\Data\UnitRef;
use App\Domains\Catalog\Enums\UnitKind;
use App\Domains\Identity\Models\User;
use App\Domains\Library\Enums\Behavior;
use App\Domains\Library\Models\Like;
use App\Domains\Library\Models\LikeNotification;
use App\Domains\Library\Notifications\LikedUnitsArrived;
use App\Domains\Library\Support\UnitKey;
use App\Domains\PlexLibrary\Data\ArrivedTitle;
use App\Domains\PlexLibrary\Events\UnitsArrived;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Deliberately not queued: it runs inside the publisher's stamp transaction, so a
 * failure here rolls the stamp back and the next plex:sync publishes the arrival again.
 */
final readonly class NotifyLikersOfArrivals
{
    public function handle(UnitsArrived $event): void
    {
        $titles = collect($event->titles)
            ->keyBy(fn (ArrivedTitle $title): string => $this->titleKey($title->titleType, $title->titleId));

        if ($titles->isEmpty()) {
            return;
        }

        $likes = $this->notifyingLikes($titles);

        if ($likes->isEmpty()) {
            return;
        }

        $told = $this->alreadyTold($likes->pluck('user_id')->unique(), $titles);

        $likes->groupBy('user_id')->each(fn (Collection $userLikes, int $userId) => $this->tell(
            $userLikes,
            $titles,
            $told->get($userId, collect()),
        ));
    }

    /**
     * @param  Collection<string, ArrivedTitle>  $titles
     * @return Collection<int, Like>
     */
    private function notifyingLikes(Collection $titles): Collection
    {
        return Like::query()
            ->whereHas('behaviors', fn (Builder $behaviors): Builder => $behaviors
                ->where('behavior', Behavior::Notify)
                ->whereNotNull('enabled_at'))
            ->where(function (Builder $query) use ($titles): void {
                foreach ($titles as $title) {
                    $query->orWhere(fn (Builder $pair): Builder => $pair
                        ->where('likeable_type', $title->titleType)
                        ->where('likeable_id', $title->titleId));
                }
            })
            ->with('user')
            ->get();
    }

    /**
     * @param  Collection<int, int>  $userIds
     * @param  Collection<string, ArrivedTitle>  $titles
     * @return Collection<int, Collection<string, LikeNotification>> per user id, keyed by UnitKey
     */
    private function alreadyTold(Collection $userIds, Collection $titles): Collection
    {
        $unitIds = $titles->flatMap(fn (ArrivedTitle $title): array => $title->units)
            ->map(fn (UnitRef $unit): int => $unit->id)
            ->unique()
            ->values();

        return LikeNotification::query()
            ->whereIn('user_id', $userIds)
            ->whereIn('unit_id', $unitIds)
            ->get()
            ->groupBy('user_id')
            ->map(fn (Collection $rows): Collection => $rows->keyBy(
                fn (LikeNotification $row): string => UnitKey::of(new UnitRef($row->unit_kind, $row->unit_id)),
            ));
    }

    /**
     * @param  Collection<int, Like>  $likes  one user's
     * @param  Collection<string, ArrivedTitle>  $titles
     * @param  Collection<string, LikeNotification>  $told  keyed by UnitKey
     */
    private function tell(Collection $likes, Collection $titles, Collection $told): void
    {
        $lines = [];
        $units = collect();

        foreach ($likes as $like) {
            /** @var ArrivedTitle $title */
            $title = $titles->get($this->titleKey($like->likeable_type, $like->likeable_id));

            $newUnits = collect($title->units)
                ->reject(fn (UnitRef $unit): bool => $told->has(UnitKey::of($unit)))
                ->values();

            if ($newUnits->isNotEmpty()) {
                $lines[] = $this->line($title->name, $newUnits);
                $units = $units->concat($newUnits);
            }
        }

        if ($units->isEmpty()) {
            return;
        }

        /** @var User $user */
        $user = $likes->first()->user;

        $this->recordTold($user, $units);
        $user->notify(new LikedUnitsArrived($lines));
    }

    /**
     * @param  Collection<int, UnitRef>  $newUnits  never empty, all of one kind
     */
    private function line(string $name, Collection $newUnits): string
    {
        return match ($newUnits->first()->kind) {
            UnitKind::Movie => $name,
            UnitKind::Episode => $name.': '.$newUnits->count().' new '.Str::plural('episode', $newUnits->count()),
        };
    }

    /**
     * @param  Collection<int, UnitRef>  $units
     */
    private function recordTold(User $user, Collection $units): void
    {
        $now = now();

        LikeNotification::query()->insert($units->map(fn (UnitRef $unit): array => [
            'user_id' => $user->id,
            'unit_kind' => $unit->kind->value,
            'unit_id' => $unit->id,
            'created_at' => $now,
            'updated_at' => $now,
        ])->all());
    }

    /**
     * The one spelling of a title's identity, shared by the published title and the
     * like pointing at it: if the two ever keyed differently, no like would find its
     * title.
     */
    private function titleKey(string $type, int $id): string
    {
        return $type.':'.$id;
    }
}
