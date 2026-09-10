<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Actions;

use App\Domains\Catalog\Enums\ArtworkType;
use App\Domains\Catalog\Models\Media;
use App\Domains\Catalog\Models\Movie;
use App\Domains\Catalog\Models\Show;
use App\Domains\Catalog\Support\RawSourceColumns;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final readonly class UpsertTmdbImages
{
    private const string SOURCE = 'tmdb';

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
     * Media rows per upsert statement, bounded independently of the caller's
     * hydrate batch: 250 titles carrying ~100 images each would put ~325k
     * placeholders in one statement, far past MySQL's 65,535 ceiling.
     */
    private const int UPSERT_CHUNK = 1000;

    /**
     * The upsert's conflict key. {@see rowsFor()} dedupes on this same tuple —
     * one statement cannot touch the same key twice.
     *
     * @var list<string>
     */
    private const array CONFLICT_KEY = ['mediable_type', 'mediable_id', '_tmdb_file_path'];

    /**
     * Persist a whole hydrate batch's TMDB artwork into the polymorphic media table.
     *
     * Deactivates every managed-type row for the batch's titles, then upserts each
     * incoming image as active — so art no longer in the payload goes stale
     * while reappearing art is reactivated. Returns the active row count across
     * every title in the batch.
     *
     * @param  Collection<int, Movie|Show>  $titles  keyed by TMDB id
     * @param  array<int, array{
     *     posters?: list<array<string, mixed>>,
     *     backdrops?: list<array<string, mixed>>,
     *     logos?: list<array<string, mixed>>,
     * }>  $images  keyed by TMDB id
     */
    public function handle(Collection $titles, array $images): int
    {
        if ($titles->isEmpty()) {
            return 0;
        }

        return DB::transaction(function () use ($titles, $images): int {
            $idsByMorphType = $this->idsByMorphType($titles);
            $rows = $this->rowsFor($titles, $images);

            $this->deactivateManagedArtwork($idsByMorphType);
            $this->upsertRows($rows);

            return $this->activeCount($idsByMorphType);
        });
    }

    /**
     * @param  Collection<int, Movie|Show>  $titles
     * @return array<string, list<int>>
     */
    private function idsByMorphType(Collection $titles): array
    {
        $idsByMorphType = [];

        foreach ($titles as $title) {
            $idsByMorphType[$title->getMorphClass()][] = $title->id;
        }

        return $idsByMorphType;
    }

    /**
     * One statement per morph type, scoped to the batch's own ids — a
     * type-only deactivate would blank every other title's artwork.
     *
     * @param  array<string, list<int>>  $idsByMorphType
     */
    private function deactivateManagedArtwork(array $idsByMorphType): void
    {
        $managedTypes = array_values(self::ARTWORK_TYPE_BY_KEY);

        foreach ($idsByMorphType as $morphType => $ids) {
            $this->mediaOfTitles($morphType, $ids)
                ->whereIn('type', $managedTypes)
                ->update(['is_active' => false]);
        }
    }

    /**
     * Build a cast-bypassing row per image for `Model::upsert()`: `type` is an
     * `ArtworkType` cast on the model, so its backing value is written directly.
     *
     * @param  Collection<int, Movie|Show>  $titles
     * @param  array<int, array<string, mixed>>  $images
     * @return list<array<string, mixed>>
     */
    private function rowsFor(Collection $titles, array $images): array
    {
        // One stamp for the whole batch — `upsert()` would otherwise date each
        // chunk of it separately, from its own `freshTimestampString()`.
        $now = now()->toDateTimeString();
        $rows = [];

        foreach ($titles as $tmdbId => $title) {
            $morphType = $title->getMorphClass();

            foreach (self::ARTWORK_TYPE_BY_KEY as $key => $type) {
                foreach ($images[$tmdbId][$key] ?? [] as $image) {
                    // A path-less image yields a path-less CDN url — meaningless, and
                    // multiple would collapse onto one `_tmdb_file_path IS NULL` row.
                    if (empty($image['file_path'])) {
                        continue;
                    }

                    // Keyed on self::CONFLICT_KEY, so a path repeated within the
                    // payload collapses here instead of colliding in the statement.
                    $rows[$morphType.'|'.$title->id.'|'.$image['file_path']] = [
                        'mediable_type' => $morphType,
                        'mediable_id' => $title->id,
                        'type' => $type->value,
                        'is_active' => true,
                        '_tmdb_file_path' => $image['file_path'],
                        ...RawSourceColumns::map(self::SOURCE, self::RAW_COLUMNS, $image),
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            }
        }

        return array_values($rows);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function upsertRows(array $rows): void
    {
        // The update list covers only this source's columns, so a TMDB write
        // never clobbers the `_tvdb_*` artwork columns on the same row.
        $update = [
            'type',
            'is_active',
            ...RawSourceColumns::names(self::SOURCE, self::RAW_COLUMNS),
            'updated_at',
        ];

        foreach (array_chunk($rows, self::UPSERT_CHUNK) as $chunk) {
            Media::upsert($chunk, self::CONFLICT_KEY, $update);
        }
    }

    /**
     * @param  array<string, list<int>>  $idsByMorphType
     */
    private function activeCount(array $idsByMorphType): int
    {
        $active = 0;

        foreach ($idsByMorphType as $morphType => $ids) {
            $active += $this->mediaOfTitles($morphType, $ids)
                ->where('is_active', true)
                ->count();
        }

        return $active;
    }

    /**
     * @param  list<int>  $ids
     * @return Builder<Media>
     */
    private function mediaOfTitles(string $morphType, array $ids): Builder
    {
        return Media::query()
            ->where('mediable_type', $morphType)
            ->whereIn('mediable_id', $ids);
    }
}
