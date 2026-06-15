<?php

namespace App\Domains\Catalog\Database\Factories;

use App\Domains\Catalog\Enums\Genre;
use App\Domains\Catalog\Models\Show;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Show>
 */
class ShowFactory extends Factory
{
    protected $model = Show::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startYear = fake()->numberBetween(1950, 2020);

        return [
            'imdb_id' => 'tt'.fake()->unique()->numerify('#######'),
            'title' => fake()->sentence(3),
            'start_year' => $startYear,
            'end_year' => fake()->optional()->numberBetween($startYear, 2025),
            'runtime' => fake()->numberBetween(20, 90),
            'genres' => [Genre::Action, Genre::Drama],
            'num_votes' => fake()->numberBetween(100, 1_000_000),
            'average_rating' => fake()->randomFloat(1, 1, 10),
        ];
    }
}
