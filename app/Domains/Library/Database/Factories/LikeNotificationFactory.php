<?php

declare(strict_types=1);

namespace App\Domains\Library\Database\Factories;

use App\Domains\Catalog\Enums\UnitKind;
use App\Domains\Identity\Models\User;
use App\Domains\Library\Models\LikeNotification;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LikeNotification>
 */
final class LikeNotificationFactory extends Factory
{
    protected $model = LikeNotification::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'unit_kind' => UnitKind::Movie,
            'unit_id' => fake()->unique()->numberBetween(1, 1_000_000),
            'notified_at' => now(),
        ];
    }
}
