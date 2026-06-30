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
            'imdb_id' => 'tt'.fake()->unique()->numerify('#######'),
            'title' => fake()->sentence(3),
            'title_type' => TitleType::TvSeries,
            'start_year' => $startYear,
            'end_year' => fake()->optional()->numberBetween($startYear, 2025),
            'runtime' => fake()->numberBetween(20, 90),
            'genres' => [Genre::Action, Genre::Drama],
            'num_votes' => fake()->numberBetween(100, 1_000_000),
            'average_rating' => fake()->randomFloat(1, 1, 10),
        ];
    }

    /**
     * Supply a representative set of TMDB-sourced attributes.
     */
    public function withTmdb(): static
    {
        return $this->state(fn (): array => [
            '_tmdb_id' => fake()->unique()->numberBetween(1, 1_000_000),
            '_tmdb_name' => fake()->sentence(3),
            '_tmdb_original_name' => fake()->sentence(3),
            '_tmdb_overview' => fake()->paragraph(),
            '_tmdb_first_air_date' => fake()->date(),
            '_tmdb_popularity' => fake()->randomFloat(3, 1, 100),
            '_tmdb_vote_average' => fake()->randomFloat(1, 1, 10),
            '_tmdb_genres' => [
                ['id' => 28, 'name' => 'Action'],
                ['id' => 18, 'name' => 'Drama'],
            ],
            '_tmdb_external_ids' => [
                'imdb_id' => 'tt'.fake()->numerify('#######'),
                'tvdb_id' => 12345,
            ],
            'tmdb_synced_at' => now(),
        ]);
    }

    /**
     * Supply a representative set of TheTVDB-sourced attributes.
     */
    public function withTvdb(): static
    {
        return $this->state(fn (): array => [
            '_tvdb_id' => fake()->unique()->numberBetween(1, 1_000_000),
            '_tvdb_name' => fake()->sentence(3),
            '_tvdb_slug' => fake()->slug(),
            '_tvdb_overview' => fake()->paragraph(),
            '_tvdb_score' => fake()->randomFloat(0, 1, 1_000_000),
            '_tvdb_firstAired' => fake()->date(),
            '_tvdb_lastAired' => fake()->date(),
            '_tvdb_year' => (string) fake()->year(),
            '_tvdb_averageRuntime' => fake()->numberBetween(20, 90),
            '_tvdb_status' => [
                'id' => 2,
                'name' => 'Ended',
                'recordType' => 'series',
                'keepUpdated' => false,
            ],
            '_tvdb_originalLanguage' => 'eng',
            '_tvdb_originalCountry' => 'usa',
            '_tvdb_genres' => [
                ['id' => 12, 'name' => 'Drama', 'slug' => 'drama'],
            ],
            '_tvdb_remoteIds' => [
                ['id' => 'tt0903747', 'type' => 2, 'sourceName' => 'IMDB'],
            ],
            'tvdb_synced_at' => now(),
        ]);
    }
}
