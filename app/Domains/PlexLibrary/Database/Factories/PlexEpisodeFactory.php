<?php

declare(strict_types=1);

namespace App\Domains\PlexLibrary\Database\Factories;

use App\Domains\PlexLibrary\Models\PlexEpisode;
use App\Domains\PlexLibrary\Models\PlexSeason;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PlexEpisode>
 */
class PlexEpisodeFactory extends Factory
{
    protected $model = PlexEpisode::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $season = PlexSeason::factory()->create();

        return [
            'plex_server_id' => $season->plex_server_id,
            'plex_show_id' => $season->plex_show_id,
            'plex_season_id' => $season->id,
            '_plex_ratingKey' => (string) fake()->unique()->numberBetween(1, 1_000_000),
            '_plex_guid' => 'plex://episode/'.fake()->uuid(),
            '_plex_parentIndex' => fake()->numberBetween(1, 20),
            '_plex_index' => fake()->numberBetween(1, 24),
            '_plex_title' => fake()->sentence(3),
            '_plex_addedAt' => now(),
            '_plex_updatedAt' => now(),
            'synced_at' => now(),
        ];
    }
}
