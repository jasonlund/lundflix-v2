<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Database\Factories;

use App\Domains\Catalog\Enums\Genre;
use App\Domains\Catalog\Enums\TitleType;
use App\Domains\Catalog\Models\Show;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Show>
 */
class ShowFactory extends Factory
{
    protected $model = Show::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startYear = fake()->numberBetween(1950, 2020);

        return [
            '_imdb_id' => 'tt'.fake()->unique()->numerify('#######'),
            '_imdb_primary_title' => fake()->sentence(3),
            '_imdb_title_type' => TitleType::TvSeries,
            '_imdb_start_year' => $startYear,
            '_imdb_end_year' => fake()->optional()->numberBetween($startYear, 2025),
            '_imdb_runtime_minutes' => fake()->numberBetween(20, 90),
            '_imdb_genres' => [Genre::Action, Genre::Drama],
            '_imdb_num_votes' => fake()->numberBetween(100, 1_000_000),
            '_imdb_average_rating' => fake()->randomFloat(1, 1, 10),
        ];
    }
}
