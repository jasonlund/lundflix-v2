<?php

declare(strict_types=1);

namespace App\Domains\Library\Actions;

use App\Domains\Library\Enums\Behavior;
use App\Domains\Library\Models\Like;
use App\Domains\Library\Models\LikeBehavior;

final readonly class ToggleLikeBehavior
{
    public function handle(Like $like, Behavior $behavior): LikeBehavior
    {
        $likeBehavior = $like->behaviors()->where('behavior', $behavior)->firstOrFail();

        // A switched-off behavior keeps its row so the like still records the choice.
        $likeBehavior->enabled_at = $likeBehavior->enabled_at === null ? now() : null;
        $likeBehavior->save();

        return $likeBehavior;
    }
}
