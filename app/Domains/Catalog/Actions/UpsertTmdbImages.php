<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Actions;

use App\Domains\Catalog\Enums\ArtworkType;
use App\Domains\Catalog\Models\Movie;
use App\Domains\Catalog\Support\RawSourceColumns;
use Illuminate\Support\Facades\DB;

class UpsertTmdbImages
{
    /**
     * TMDB image payload keys mapped to the artwork type they manage.
     */
    private const array ARTWORK_TYPE_BY_KEY = [
        'posters' => ArtworkType::Poster,
        'backdrops' => ArtworkType::Backdrop,
        'logos' => ArtworkType::Logo,
    ];

    /**
     * Raw TMDB image keys mapped 1:1 onto `_tmdb_*` columns, value taken raw.
     *
     * @var list<string>
     */
    private const array RAW_COLUMNS = [
        'iso_639_1', 'iso_3166_1', 'vote_average', 'vote_count',
        'width', 'height', 'aspect_ratio',
    ];

    /**
     * Persist a movie's TMDB artwork into the polymorphic media table.
     *
     * Deactivates every managed-type row for the movie, then upserts each
     * incoming image as active — so art no longer in the payload goes stale
     * while reappearing art is reactivated. Returns the active row count.
     *
     * @param  array{
     *     posters?: list<array<string, mixed>>,
     *     backdrops?: list<array<string, mixed>>,
     *     logos?: list<array<string, mixed>>,
     * }  $images
     */
    public function handle(Movie $movie, array $images): int
    {
        return DB::transaction(function () use ($movie, $images): int {
            $movie->media()
                ->whereIn('type', array_values(self::ARTWORK_TYPE_BY_KEY))
                ->update(['is_active' => false]);

            foreach (self::ARTWORK_TYPE_BY_KEY as $key => $type) {
                foreach ($images[$key] ?? [] as $image) {
                    $movie->media()->updateOrCreate(
                        ['_tmdb_file_path' => $image['file_path'] ?? null],
                        ['type' => $type, 'is_active' => true, ...$this->rawAttributes($image)],
                    );
                }
            }

            return $movie->media()->where('is_active', true)->count();
        });
    }

    /**
     * Map a raw TMDB image entry to its source-prefixed media columns.
     *
     * @param  array<string, mixed>  $image
     * @return array<string, mixed>
     */
    private function rawAttributes(array $image): array
    {
        return RawSourceColumns::map('tmdb', self::RAW_COLUMNS, $image);
    }
}
