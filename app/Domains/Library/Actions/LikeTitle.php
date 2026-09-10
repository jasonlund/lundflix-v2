<?php

declare(strict_types=1);

namespace App\Domains\Library\Actions;

use App\Domains\Identity\Models\User;
use App\Domains\Library\Enums\Behavior;
use App\Domains\Library\Models\Like;
use Illuminate\Database\Eloquent\Model;

final readonly class LikeTitle
{
    public function handle(User $user, Model $title): Like
    {
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
    }
}
