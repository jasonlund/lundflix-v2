<?php

declare(strict_types=1);

namespace App\Domains\PlexLibrary\Database\Factories;

use App\Domains\PlexLibrary\Models\PlexLibrary;
use App\Domains\PlexLibrary\Models\PlexMovie;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PlexMovie>
 */
final class PlexMovieFactory extends Factory
{
    protected $model = PlexMovie::class;

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
            '_plex_guid' => 'plex://movie/'.fake()->uuid(),
            '_plex_title' => fake()->sentence(3),
            '_plex_year' => fake()->year(),
            '_plex_addedAt' => now(),
            '_plex_updatedAt' => now(),
            'synced_at' => now(),
        ];
    }
}
