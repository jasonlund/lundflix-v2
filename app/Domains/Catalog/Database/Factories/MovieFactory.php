<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Database\Factories;

use App\Domains\Catalog\Enums\TitleType;
use App\Domains\Catalog\Models\Movie;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Movie>
 */
class MovieFactory extends Factory
{
    protected $model = Movie::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            '_imdb_id' => 'tt'.fake()->unique()->numerify('#######'),
            '_imdb_primary_title' => fake()->sentence(3),
            '_imdb_title_type' => TitleType::Movie,
            '_imdb_start_year' => fake()->numberBetween(1950, 2025),
            '_imdb_runtime_minutes' => fake()->numberBetween(60, 240),
            '_imdb_genres' => ['Action', 'Drama'],
            '_imdb_num_votes' => fake()->numberBetween(100, 1_000_000),
            '_imdb_average_rating' => fake()->randomFloat(1, 1, 10),
        ];
    }

    /**
     * Supply a representative set of TMDB-sourced attributes.
     */
    public function withTmdb(): static
    {
        return $this->state(fn (): array => [
            '_tmdb_id' => fake()->unique()->numberBetween(1, 1_000_000),
            '_tmdb_title' => fake()->sentence(3),
            '_tmdb_original_title' => fake()->sentence(3),
            '_tmdb_overview' => fake()->paragraph(),
            '_tmdb_runtime' => fake()->numberBetween(60, 240),
            '_tmdb_release_date' => fake()->date(),
            '_tmdb_video' => false,
            '_tmdb_genres' => [
                ['id' => 28, 'name' => 'Action'],
                ['id' => 18, 'name' => 'Drama'],
            ],
            '_tmdb_belongs_to_collection' => [
                'id' => 10,
                'name' => 'Example Collection',
            ],
            '_tmdb_release_dates' => [
                ['iso_3166_1' => 'US', 'release_dates' => [['certification' => 'PG-13']]],
            ],
            'tmdb_synced_at' => now(),
        ]);
    }
}
