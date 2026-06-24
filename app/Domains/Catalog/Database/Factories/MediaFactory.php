<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Database\Factories;

use App\Domains\Catalog\Enums\ArtworkType;
use App\Domains\Catalog\Models\Media;
use App\Domains\Catalog\Models\Movie;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Media>
 */
class MediaFactory extends Factory
{
    protected $model = Media::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'mediable_id' => Movie::factory(),
            'mediable_type' => (new Movie)->getMorphClass(),
            'type' => ArtworkType::Poster,
            'is_active' => true,
            '_tmdb_file_path' => '/'.fake()->lexify('????????').'.jpg',
            '_tmdb_iso_639_1' => 'en',
            '_tmdb_vote_average' => fake()->randomFloat(3, 0, 10),
            '_tmdb_vote_count' => fake()->numberBetween(0, 500),
            '_tmdb_width' => 500,
            '_tmdb_height' => 750,
            '_tmdb_aspect_ratio' => 0.667,
        ];
    }
}
