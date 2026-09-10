<?php

declare(strict_types=1);

namespace App\Domains\Library\Actions;

use App\Domains\Identity\Models\User;
use App\Domains\Library\Models\Like;
use Illuminate\Database\Eloquent\Model;

final readonly class UnlikeTitle
{
    public function handle(User $user, Model $title): void
    {
        // The behaviors go with it on the table's cascade, so they need no delete here.
        Like::query()
            ->where('user_id', $user->getKey())
            // The provider's enforced morph map makes this the alias ('movie'), not the FQCN.
            ->where('likeable_type', $title->getMorphClass())
            ->where('likeable_id', $title->getKey())
            ->delete();
    }
}
