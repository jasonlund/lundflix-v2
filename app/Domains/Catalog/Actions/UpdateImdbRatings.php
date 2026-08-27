<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Actions;

use App\Domains\Catalog\Models\Movie;
use App\Domains\Catalog\Models\Show;
use App\Domains\Catalog\Support\BulkCaseUpdate;
use App\Domains\Catalog\Support\RawSourceColumns;
use Illuminate\Database\Eloquent\Builder;

final readonly class UpdateImdbRatings
{
    private const string SOURCE = 'imdb';

    /**
     * Raw `title.ratings` keys mapped 1:1 onto `_imdb_*` columns, value taken raw.
     *
     * @var list<string>
     */
    private const array RAW_COLUMNS = [
        'numVotes',
        'averageRating',
    ];

    public function __construct(private BulkCaseUpdate $bulkCaseUpdate) {}

    /**
     * @param  array<string, array{numVotes: int, averageRating: float}>  $ratings
     * @return array{movies: int, shows: int}
     */
    public function handle(array $ratings): array
    {
        return [
            'movies' => $this->updateTable(Movie::query(), $ratings),
            'shows' => $this->updateTable(Show::query(), $ratings),
        ];
    }

    /**
     * Apply the supplied ratings to one table in a single bulk CASE update,
     * returning the number of titles matched (and updated).
     *
     * @param  Builder<Movie>|Builder<Show>  $query
     * @param  array<string, array{numVotes: int, averageRating: float}>  $ratings
     */
    private function updateTable(Builder $query, array $ratings): int
    {
        $valuesById = array_map(
            fn (array $rating): array => RawSourceColumns::map(self::SOURCE, self::RAW_COLUMNS, $rating),
            $ratings,
        );

        $matchedIds = $this->bulkCaseUpdate->handle(
            $query,
            $valuesById,
            RawSourceColumns::names(self::SOURCE, self::RAW_COLUMNS),
        );

        return count($matchedIds);
    }
}
