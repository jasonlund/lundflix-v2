<?php

declare(strict_types=1);

namespace App\Domains\Library\Actions;

use App\Domains\Identity\Models\User;
use App\Domains\Library\Enums\Behavior;
use App\Domains\Library\Models\Like;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

final readonly class LikeTitle
{
    /**
     * A like is only meaningful alongside its behavior rows, so a part-way failure
     * must not leave a reader observing a like carrying some of them.
     */
    public function handle(User $user, Model $title): Like
    {
        return DB::transaction(function () use ($user, $title): Like {
            $like = Like::firstOrCreate([
                'user_id' => $user->getKey(),
                // The provider's enforced morph map makes this the alias ('movie'), not the FQCN.
                'likeable_type' => $title->getMorphClass(),
                'likeable_id' => $title->getKey(),
            ]);

            foreach (Behavior::cases() as $behavior) {
                $like->behaviors()->firstOrCreate(
                    ['behavior' => $behavior],
                    ['enabled_at' => now()],
                );
            }

            // Creating through the relation query leaves the parent's relation unset, so
            // the caller only sees the behaviors if they are loaded here.
            return $like->load('behaviors');
        });
    }
}
