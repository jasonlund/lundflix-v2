<?php

declare(strict_types=1);

namespace App\Domains\PlexLibrary\Database\Factories;

use App\Domains\PlexLibrary\Models\PlexLibrary;
use App\Domains\PlexLibrary\Models\PlexServer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PlexLibrary>
 */
class PlexLibraryFactory extends Factory
{
    protected $model = PlexLibrary::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'plex_server_id' => PlexServer::factory(),
            '_plex_key' => (string) fake()->unique()->numberBetween(1, 1_000_000),
            '_plex_type' => fake()->randomElement(['movie', 'show']),
            '_plex_title' => fake()->words(2, true),
            '_plex_uuid' => fake()->uuid(),
            'synced_at' => now(),
        ];
    }
}
