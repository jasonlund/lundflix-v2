<?php

declare(strict_types=1);

namespace App\Domains\Library\Database\Factories;

use App\Domains\Library\Enums\Behavior;
use App\Domains\Library\Models\Like;
use App\Domains\Library\Models\LikeBehavior;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LikeBehavior>
 */
final class LikeBehaviorFactory extends Factory
{
    protected $model = LikeBehavior::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'like_id' => Like::factory(),
            'behavior' => Behavior::Acquire,
            'enabled_at' => now(),
        ];
    }

    /**
     * Null `enabled_at` is the off state.
     */
    public function disabled(): static
    {
        return $this->state(fn (): array => [
            'enabled_at' => null,
        ]);
    }
}
