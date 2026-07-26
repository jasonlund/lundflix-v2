<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Support;

use App\Domains\Catalog\Models\Movie;
use App\Domains\Catalog\Models\Show;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Expression;

final class BulkCaseUpdate
{
    /**
     * @param  Builder<Movie>|Builder<Show>  $query
     * @param  array<string, array<string, mixed>>  $valuesById  keyed by _imdb_id => [column => value]
     * @param  list<string>  $columns  the DB columns to write, in SET-clause order
     * @return list<string> the matched _imdb_ids
     */
    public function handle(Builder $query, array $valuesById, array $columns): array
    {
        $matchedIds = (clone $query)
            ->whereIn('_imdb_id', array_keys($valuesById))
            ->pluck('_imdb_id')
            ->all();

        if ($matchedIds === []) {
            return [];
        }

        $caseBindings = [];
        $set = [];

        foreach ($columns as $column) {
            $case = $this->buildCase($matchedIds, fn (string $imdbId): mixed => $valuesById[$imdbId][$column]);

            $set[$column] = new Expression($case['sql']);
            $caseBindings = array_merge($caseBindings, $case['bindings']);
        }

        $update = (clone $query)->getQuery()->whereIn('_imdb_id', $matchedIds);

        // The CASE placeholders sit in the SET clause, which the grammar renders
        // *before* the WHERE clause; Expression SET values carry no bindings of
        // their own. prepareBindingsForUpdate() prepends the 'join' binding slot
        // ahead of the where bindings, so the CASE bindings must live there — in
        // SET-clause column order — to line up with their placeholders. Append to
        // (never replace) any existing join bindings: a join/global-scope on the
        // model would otherwise be silently dropped, shifting every placeholder
        // and corrupting the update.
        $update->bindings['join'] = array_merge($update->bindings['join'] ?? [], $caseBindings);
        $update->update($set);

        return $matchedIds;
    }

    /**
     * Build a `CASE _imdb_id WHEN ? THEN ? ... END` expression for the matched
     * ids, with bindings in placeholder order (id, value, id, value, ...).
     *
     * @param  list<string>  $matchedIds
     * @param  callable(string): mixed  $valueFor
     * @return array{sql: string, bindings: list<mixed>}
     */
    private function buildCase(array $matchedIds, callable $valueFor): array
    {
        $sql = 'CASE _imdb_id';
        $bindings = [];

        foreach ($matchedIds as $imdbId) {
            $sql .= ' WHEN ? THEN ?';
            $bindings[] = $imdbId;
            $bindings[] = $valueFor($imdbId);
        }

        return ['sql' => $sql.' END', 'bindings' => $bindings];
    }
}
