<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Actions;

use App\Domains\Catalog\Data\TitleImportCounts;
use App\Domains\Catalog\Models\Movie;
use App\Domains\Catalog\Models\Show;
use App\Domains\Catalog\Support\BulkCaseUpdate;
use App\Domains\Catalog\Support\RawSourceColumns;
use Illuminate\Database\Eloquent\Builder;

final readonly class ImportImdbTitles
{
    private const string SOURCE = 'imdb';

    /**
     * Raw `title.basics` keys mapped 1:1 onto `_imdb_*` columns, value taken raw.
     *
     * @var list<string>
     */
    private const array RAW_COLUMNS = [
        'titleType',
        'primaryTitle',
        'originalTitle',
        'startYear',
        'endYear',
        'runtimeMinutes',
        'genres',
    ];

    /**
     * `_imdb_*` columns cast to `array` on the model; a bulk CASE update writes
     * raw SQL values without invoking the model's casts, so these must be passed
     * already json-encoded.
     *
     * @var list<string>
     */
    private const array JSON_COLUMNS = [
        '_imdb_genres',
    ];

    public function __construct(private BulkCaseUpdate $bulkCaseUpdate) {}

    /**
     * @param  array<string, array<string, mixed>>  $rows  keyed by tconst => the raw title.basics row
     */
    public function handle(array $rows): TitleImportCounts
    {
        return new TitleImportCounts(
            movies: $this->updateTable(Movie::query(), $rows),
            shows: $this->updateTable(Show::query(), $rows),
        );
    }

    /**
     * Apply the supplied basics rows to one table in a single bulk CASE update,
     * returning the number of titles matched (and updated).
     *
     * @param  Builder<Movie>|Builder<Show>  $query
     * @param  array<string, array<string, mixed>>  $rows
     */
    private function updateTable(Builder $query, array $rows): int
    {
        $valuesById = array_map(
            $this->rawImdbColumns(...),
            $rows,
        );

        $matchedIds = $this->bulkCaseUpdate->handle(
            $query,
            $valuesById,
            RawSourceColumns::names(self::SOURCE, self::RAW_COLUMNS),
        );

        if ($matchedIds === []) {
            return 0;
        }

        (clone $query)->whereIn('_imdb_id', $matchedIds)->searchable();

        return count($matchedIds);
    }

    /**
     * Build a cast-bypassing column set for the bulk CASE update: values are
     * taken raw from the basics row, with the json columns pre-encoded.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function rawImdbColumns(array $row): array
    {
        $columns = RawSourceColumns::map(self::SOURCE, self::RAW_COLUMNS, $row);

        foreach (self::JSON_COLUMNS as $column) {
            $columns[$column] = $columns[$column] === null ? null : json_encode($columns[$column]);
        }

        return $columns;
    }
}
