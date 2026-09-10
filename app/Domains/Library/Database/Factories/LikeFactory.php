<?php

declare(strict_types=1);

namespace App\Domains\Library\Database\Factories;

use App\Domains\Identity\Models\User;
use App\Domains\Library\Models\Like;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Like>
 */
final class LikeFactory extends Factory
{
    protected $model = Like::class;

    /**
     * The liked title is supplied by the caller — `->for($movie, 'likeable')` —
     * so this domain's factory never has to name another domain's models.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
        ];
    }
}
