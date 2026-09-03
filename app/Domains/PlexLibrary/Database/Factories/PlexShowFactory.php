<?php

declare(strict_types=1);

namespace App\Domains\PlexLibrary\Database\Factories;

use App\Domains\PlexLibrary\Models\PlexLibrary;
use App\Domains\PlexLibrary\Models\PlexShow;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PlexShow>
 */
final class PlexShowFactory extends Factory
{
    protected $model = PlexShow::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $library = PlexLibrary::factory()->create();

        return [
            'plex_server_id' => $library->plex_server_id,
            'plex_library_id' => $library->id,
            '_plex_ratingKey' => (string) fake()->unique()->numberBetween(1, 1_000_000),
            '_plex_guid' => 'plex://show/'.fake()->uuid(),
            '_plex_title' => fake()->sentence(3),
            '_plex_year' => fake()->year(),
            '_plex_leafCount' => fake()->numberBetween(1, 200),
            '_plex_childCount' => fake()->numberBetween(1, 10),
            '_plex_addedAt' => now(),
            '_plex_updatedAt' => now(),
            'synced_at' => now(),
            'episodes_synced_at' => now(),
        ];
    }
}
