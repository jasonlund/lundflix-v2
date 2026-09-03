<?php

declare(strict_types=1);

namespace App\Domains\PlexLibrary\Database\Factories;

use App\Domains\PlexLibrary\Models\PlexEpisode;
use App\Domains\PlexLibrary\Models\PlexSeason;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PlexEpisode>
 */
final class PlexEpisodeFactory extends Factory
{
    protected $model = PlexEpisode::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // Memoized per instance so the two derived keys share one lookup.
        $parent = null;
        $season = function (array $attributes) use (&$parent): PlexSeason {
            return $parent?->getKey() === $attributes['plex_season_id']
                ? $parent
                : $parent = PlexSeason::findOrFail($attributes['plex_season_id']);
        };

        return [
            // Must stay ahead of the derived keys: Laravel resolves closure attributes
            // in definition order, so the season id is only an id once it has been passed.
            'plex_season_id' => PlexSeason::factory(),
            'plex_show_id' => fn (array $attributes) => $season($attributes)->plex_show_id,
            'plex_server_id' => fn (array $attributes) => $season($attributes)->plex_server_id,
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
