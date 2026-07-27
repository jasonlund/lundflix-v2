<?php

declare(strict_types=1);

namespace App\Domains\PlexLibrary\Database\Factories;

use App\Domains\PlexLibrary\Models\PlexSeason;
use App\Domains\PlexLibrary\Models\PlexShow;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PlexSeason>
 */
class PlexSeasonFactory extends Factory
{
    protected $model = PlexSeason::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // Must stay ahead of the derived key: Laravel resolves closure attributes
            // in definition order, so the show id is only an id once it has been passed.
            'plex_show_id' => PlexShow::factory(),
            'plex_server_id' => fn (array $attributes) => PlexShow::findOrFail($attributes['plex_show_id'])->plex_server_id,
            '_plex_ratingKey' => (string) fake()->unique()->numberBetween(1, 1_000_000),
            '_plex_guid' => 'plex://season/'.fake()->uuid(),
            '_plex_index' => fake()->numberBetween(1, 20),
            '_plex_title' => fake()->sentence(3),
            '_plex_leafCount' => fake()->numberBetween(1, 30),
            '_plex_addedAt' => now(),
            '_plex_updatedAt' => now(),
            'synced_at' => now(),
        ];
    }
}
