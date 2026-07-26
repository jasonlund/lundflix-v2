<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Actions;

use App\Domains\Catalog\Models\Movie;
use App\Domains\Catalog\Models\Show;
use App\Domains\Catalog\Support\BulkCaseUpdate;
use Illuminate\Database\Eloquent\Builder;

final readonly class UpdateImdbRatings
{
    public function __construct(private BulkCaseUpdate $bulkCaseUpdate) {}

    /**
     * @param  array<string, array{num_votes: int, average_rating: float}>  $ratings
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
     * @param  array<string, array{num_votes: int, average_rating: float}>  $ratings
     */
    private function updateTable(Builder $query, array $ratings): int
    {
        $valuesById = [];

        foreach ($ratings as $imdbId => $rating) {
            $valuesById[$imdbId] = [
                '_imdb_numVotes' => $rating['num_votes'],
                '_imdb_averageRating' => $rating['average_rating'],
            ];
        }

        $matchedIds = $this->bulkCaseUpdate->handle(
            $query,
            $valuesById,
            ['_imdb_numVotes', '_imdb_averageRating'],
        );

        if ($matchedIds === []) {
            return 0;
        }

        (clone $query)->whereIn('_imdb_id', $matchedIds)->searchable();

        return count($matchedIds);
    }
}
