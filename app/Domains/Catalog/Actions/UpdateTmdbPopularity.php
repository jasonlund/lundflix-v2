<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Actions;

use App\Domains\Catalog\Models\Movie;
use App\Domains\Catalog\Models\Show;
use App\Domains\Catalog\Support\BulkCaseUpdate;
use App\Domains\Common\Support\SourceId;
use Illuminate\Database\Eloquent\Builder;

final readonly class UpdateTmdbPopularity
{
    /** @var list<string> */
    private const array COLUMNS = ['_tmdb_popularity'];

    public function __construct(private BulkCaseUpdate $bulkCaseUpdate) {}

    /**
     * Refresh `_tmdb_popularity` from one batch of daily-id-export rows, returning
     * how many held rows the batch matched.
     *
     * Popularity is the only column written. The export also republishes
     * `adult`/`video`/`original_title`, but those are a detail fetch's to own —
     * writing them here would overwrite hydrated values from a payload that is not
     * authoritative for them.
     *
     * `updated_at` is deliberately left stale (`$touch: false`): popularity is not
     * in `toSearchableArray()`, so stamping it would drag the whole catalog through
     * the search engine every run for a value the index never holds.
     *
     * @param  Builder<Movie>|Builder<Show>  $query
     * @param  list<array<string, mixed>>  $rows
     */
    public function handle(Builder $query, array $rows): int
    {
        $valuesById = [];

        foreach ($rows as $row) {
            $id = SourceId::tmdb($row['id'] ?? null);
            $popularity = $row['popularity'] ?? null;

            if ($id === null || ! is_numeric($popularity)) {
                continue;
            }

            $valuesById[$id] = ['_tmdb_popularity' => (float) $popularity];
        }

        if ($valuesById === []) {
            return 0;
        }

        return count($this->bulkCaseUpdate->handle($query, $valuesById, self::COLUMNS, keyColumn: '_tmdb_id', touch: false));
    }
}
