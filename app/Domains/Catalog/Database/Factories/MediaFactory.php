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

    /**
     * A single-source TVDB artwork row: raw `_tvdb_*` values set, `_tmdb_*` nulled.
     */
    public function withTvdb(): static
    {
        return $this->state(fn (array $attributes): array => [
            '_tmdb_file_path' => null,
            '_tmdb_iso_639_1' => null,
            '_tmdb_vote_average' => null,
            '_tmdb_vote_count' => null,
            '_tmdb_width' => null,
            '_tmdb_height' => null,
            '_tmdb_aspect_ratio' => null,
            '_tvdb_image' => 'https://artworks.thetvdb.com/banners/posters/81189-10.jpg',
            '_tvdb_type' => 2,
            '_tvdb_language' => 'eng',
            '_tvdb_width' => 680,
            '_tvdb_height' => 1000,
            '_tvdb_score' => 100141,
            '_tvdb_thumbnail' => 'https://artworks.thetvdb.com/banners/posters/81189-10_t.jpg',
        ]);
    }
}
