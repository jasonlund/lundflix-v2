<?php

declare(strict_types=1);

namespace App\Domains\Library\Actions;

use App\Domains\Library\Enums\Behavior;
use App\Domains\Library\Models\Like;
use App\Domains\Library\Models\LikeBehavior;
use Illuminate\Support\Facades\DB;

final readonly class ToggleLikeBehavior
{
    public function handle(Like $like, Behavior $behavior): LikeBehavior
    {
        return DB::transaction(function () use ($like, $behavior): LikeBehavior {
            // Inverting `enabled_at` in PHP means concurrent toggles would otherwise
            // read the same value and write the same result, losing one of them.
            $likeBehavior = $like->behaviors()->where('behavior', $behavior)->lockForUpdate()->firstOrFail();

            // A switched-off behavior keeps its row so the like still records the choice.
            $likeBehavior->enabled_at = $likeBehavior->enabled_at === null ? now() : null;
            $likeBehavior->save();

            return $likeBehavior;
        });
    }
}
