<?php

declare(strict_types=1);

namespace App\Domains\PlexLibrary\Database\Factories;

use App\Domains\PlexLibrary\Models\PlexServer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PlexServer>
 */
final class PlexServerFactory extends Factory
{
    protected $model = PlexServer::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            '_plex_clientIdentifier' => fake()->unique()->uuid(),
            '_plex_name' => fake()->words(2, true),
            'uri' => fake()->url(),
            'synced_at' => now(),
        ];
    }
}
