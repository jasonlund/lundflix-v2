<?php

namespace App\Domains\Catalog\Actions;

use App\Domains\Catalog\Models\Movie;
use App\Domains\Catalog\Models\Show;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Expression;

class UpdateImdbRatings
{
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
        $matchedIds = (clone $query)
            ->whereIn('imdb_id', array_keys($ratings))
            ->pluck('imdb_id')
            ->all();

        if ($matchedIds === []) {
            return 0;
        }

        $numVotes = $this->buildCase($matchedIds, fn (string $imdbId): int => $ratings[$imdbId]['num_votes']);
        $averageRating = $this->buildCase($matchedIds, fn (string $imdbId): float => $ratings[$imdbId]['average_rating']);

        $update = (clone $query)->getQuery()->whereIn('imdb_id', $matchedIds);

        // The CASE placeholders sit in the SET clause, which the grammar renders
        // *before* the WHERE clause; Expression SET values carry no bindings of
        // their own. prepareBindingsForUpdate() prepends the 'join' binding slot
        // ahead of the where bindings, so the CASE bindings must live there — in
        // SET-clause column order (num_votes then average_rating) — to line up
        // with their placeholders.
        $update->bindings['join'] = array_merge($numVotes['bindings'], $averageRating['bindings']);
        $update->update([
            'num_votes' => new Expression($numVotes['sql']),
            'average_rating' => new Expression($averageRating['sql']),
        ]);

        (clone $query)->whereIn('imdb_id', $matchedIds)->searchable();

        return count($matchedIds);
    }

    /**
     * Build a `CASE imdb_id WHEN ? THEN ? ... END` expression for the matched
     * ids, with bindings in placeholder order (id, value, id, value, ...).
     *
     * @param  list<string>  $matchedIds
     * @param  callable(string): (int|float)  $valueFor
     * @return array{sql: string, bindings: list<string|int|float>}
     */
    private function buildCase(array $matchedIds, callable $valueFor): array
    {
        $sql = 'CASE imdb_id';
        $bindings = [];

        foreach ($matchedIds as $imdbId) {
            $sql .= ' WHEN ? THEN ?';
            $bindings[] = $imdbId;
            $bindings[] = $valueFor($imdbId);
        }

        return ['sql' => $sql.' END', 'bindings' => $bindings];
    }
}
