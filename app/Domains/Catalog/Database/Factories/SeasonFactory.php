<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Database\Factories;

use App\Domains\Catalog\Models\Season;
use App\Domains\Catalog\Models\Show;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Season>
 */
class SeasonFactory extends Factory
{
    protected $model = Season::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'show_id' => Show::factory(),
            '_tvdb_id' => fake()->unique()->numberBetween(1, 1_000_000),
            '_tvdb_seriesId' => fake()->numberBetween(1, 1_000_000),
            '_tvdb_type' => [
                'id' => 1,
                'name' => 'Aired Order',
                'type' => 'official',
            ],
            '_tvdb_number' => fake()->numberBetween(1, 20),
            '_tvdb_image' => fake()->imageUrl(),
            '_tvdb_imageType' => fake()->numberBetween(1, 10),
            'tvdb_synced_at' => now(),
        ];
    }
}
