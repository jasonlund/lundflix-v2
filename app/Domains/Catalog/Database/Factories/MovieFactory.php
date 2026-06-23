<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Database\Factories;

use App\Domains\Catalog\Enums\Genre;
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
            'imdb_id' => 'tt'.fake()->unique()->numerify('#######'),
            'title' => fake()->sentence(3),
            'title_type' => TitleType::Movie,
            'year' => fake()->numberBetween(1950, 2025),
            'runtime' => fake()->numberBetween(60, 240),
            'genres' => [Genre::Action, Genre::Drama],
            'num_votes' => fake()->numberBetween(100, 1_000_000),
            'average_rating' => fake()->randomFloat(1, 1, 10),
        ];
    }
}
