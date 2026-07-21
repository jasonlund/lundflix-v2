<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Database\Factories;

use App\Domains\Catalog\Models\Episode;
use App\Domains\Catalog\Models\Show;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Episode>
 */
class EpisodeFactory extends Factory
{
    protected $model = Episode::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'show_id' => Show::factory(),
            'season_id' => null,
            '_tvdb_id' => fake()->unique()->numberBetween(1, 1_000_000),
            '_tvdb_seriesId' => fake()->numberBetween(1, 1_000_000),
            '_tvdb_name' => fake()->sentence(3),
            '_tvdb_aired' => fake()->date(),
            '_tvdb_runtime' => fake()->numberBetween(20, 90),
            '_tvdb_overview' => fake()->paragraph(),
            '_tvdb_image' => fake()->imageUrl(),
            '_tvdb_number' => fake()->numberBetween(1, 24),
            '_tvdb_absoluteNumber' => fake()->numberBetween(1, 500),
            '_tvdb_seasonNumber' => fake()->numberBetween(1, 20),
            '_tvdb_finaleType' => null,
            '_tvdb_year' => (int) fake()->year(),
            'tvdb_synced_at' => now(),
        ];
    }
}
