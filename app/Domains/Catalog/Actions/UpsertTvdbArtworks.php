<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Actions;

use App\Domains\Catalog\Enums\ArtworkType;
use App\Domains\Catalog\Models\Movie;
use App\Domains\Catalog\Models\Show;
use App\Domains\Catalog\Support\RawSourceColumns;
use Illuminate\Support\Facades\DB;

final class UpsertTvdbArtworks
{
    /**
     * Raw TVDB artwork keys mapped 1:1 onto `_tvdb_*` columns, value taken raw.
     *
     * @var list<string>
     */
    private const array RAW_COLUMNS = [
        'image', 'type', 'language', 'width', 'height', 'score', 'thumbnail',
    ];

    /**
     * Persist a movie's or show's TVDB artwork into the polymorphic media table.
     *
     * Deactivates every TVDB-owned row for the title (keyed on `_tvdb_image`,
     * so TMDB rows are untouched), then upserts each mappable incoming artwork
     * as active — so art no longer in the payload goes stale while reappearing
     * art is reactivated. Returns the active row count.
     *
     * @param  array<int, array<string, mixed>>  $artworks
     */
    public function handle(Movie|Show $mediable, array $artworks): int
    {
        return DB::transaction(function () use ($mediable, $artworks): int {
            $mediable->media()
                ->whereNotNull('_tvdb_image')
                ->update(['is_active' => false]);

            foreach ($artworks as $artwork) {
                $type = ArtworkType::fromTvdb((int) $artwork['type']);

                if (! $type instanceof ArtworkType) {
                    continue;
                }

                // A path-less image yields a path-less url — meaningless, and
                // multiple would collapse onto one `_tvdb_image IS NULL` row.
                if (empty($artwork['image'])) {
                    continue;
                }

                $mediable->media()->updateOrCreate(
                    ['_tvdb_image' => $artwork['image']],
                    ['type' => $type, 'is_active' => true, ...$this->rawAttributes($artwork)],
                );
            }

            return $mediable->media()->where('is_active', true)->count();
        });
    }

    /**
     * Map a raw TVDB artwork entry to its source-prefixed media columns.
     *
     * @param  array<string, mixed>  $artwork
     * @return array<string, mixed>
     */
    private function rawAttributes(array $artwork): array
    {
        return RawSourceColumns::map('tvdb', self::RAW_COLUMNS, $artwork);
    }
}
