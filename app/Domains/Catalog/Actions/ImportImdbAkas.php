<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Actions;

use App\Domains\Catalog\Models\Movie;
use App\Domains\Catalog\Models\Show;
use App\Domains\Catalog\Support\BulkCaseUpdate;
use Illuminate\Database\Eloquent\Builder;

final readonly class ImportImdbAkas
{
    /**
     * A title's whole aka list lands in this single column, cast to `array` on the
     * model. A bulk CASE update writes raw SQL values without invoking that cast,
     * so the list is passed already json-encoded.
     */
    private const string COLUMN = '_imdb_akas';

    /**
     * The stored keys of a single aka row, in IMDb's `title.akas` order. `titleId`
     * is deliberately absent: it is the batch key, so repeating it on every one of
     * a title's rows is dead weight.
     *
     * @var list<string>
     */
    private const array ROW_KEYS = [
        'ordering',
        'title',
        'region',
        'language',
        'types',
        'attributes',
        'isOriginalTitle',
    ];

    public function __construct(private BulkCaseUpdate $bulkCaseUpdate) {}

    /**
     * @param  array<string, list<array<string, mixed>>>  $akas  keyed by titleId => that title's aka rows
     * @return array{movies: int, shows: int}
     */
    public function handle(array $akas): array
    {
        return [
            'movies' => $this->updateTable(Movie::query(), $akas),
            'shows' => $this->updateTable(Show::query(), $akas),
        ];
    }

    /**
     * Apply the supplied aka rows to one table in a single bulk CASE update,
     * returning the number of titles matched (and updated).
     *
     * @param  Builder<Movie>|Builder<Show>  $query
     * @param  array<string, list<array<string, mixed>>>  $akas
     */
    private function updateTable(Builder $query, array $akas): int
    {
        $valuesById = array_map(
            $this->akasColumn(...),
            $akas,
        );

        $matchedIds = $this->bulkCaseUpdate->handle($query, $valuesById, [self::COLUMN]);

        if ($matchedIds === []) {
            return 0;
        }

        (clone $query)->whereIn('_imdb_id', $matchedIds)->searchable();

        return count($matchedIds);
    }

    /**
     * The bulk CASE update's single-column value set for one title.
     *
     * Aka values reach here as raw bytes split off a gzip TSV stream, never
     * round-tripped through a json decode that would have vouched for their
     * encoding. Unguarded, one mis-encoded byte makes json_encode return false,
     * whose `(string)` cast is '' — a value the native json column rejects,
     * taking down every title in the batch's single CASE update. Substituting
     * U+FFFD keeps the junk row importable, as elsewhere with junk upstream data.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, string>
     */
    private function akasColumn(array $rows): array
    {
        return [self::COLUMN => (string) json_encode(
            array_map($this->storedRow(...), $rows),
            JSON_INVALID_UTF8_SUBSTITUTE,
        )];
    }

    /**
     * Each row is rebuilt key by key so the stored shape is exactly {@see ROW_KEYS}
     * — a field IMDb omits stores as null rather than vanishing from the row.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function storedRow(array $row): array
    {
        $stored = [];

        foreach (self::ROW_KEYS as $key) {
            $stored[$key] = $row[$key] ?? null;
        }

        return $stored;
    }
}
