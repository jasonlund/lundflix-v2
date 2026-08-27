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

        $setBindings = [];
        $set = [];

        foreach ($columns as $column) {
            $case = $this->buildCase($matchedIds, fn (string $imdbId): mixed => $valuesById[$imdbId][$column]);

            $set[$column] = new Expression($case['sql']);
            $setBindings = array_merge($setBindings, $case['bindings']);
        }

        // A query-builder update bypasses Eloquent's timestamps, so stamp
        // updated_at here — uniform across the matched rows, hence a bare
        // placeholder rather than a CASE. It stays an Expression so its binding
        // joins the others in the single slot below; passed as a plain value it
        // would land in the grammar's 'value' slot, which MySQL orders after the
        // join bindings but SQLiteGrammar (overriding prepareBindingsForUpdate)
        // orders before them — aligned under one grammar, corrupt under the other.
        $set['updated_at'] = new Expression('?');
        $setBindings[] = now();

        $update = (clone $query)->toBase()->whereIn('_imdb_id', $matchedIds);

        // Every SET placeholder — CASE columns and updated_at alike — carries its
        // binding in this one 'join' slot, in SET-clause order: the grammar renders
        // SET before WHERE, Expression values contribute no bindings of their own,
        // and prepareBindingsForUpdate() puts the join slot ahead of the where
        // bindings. Append to (never replace) any existing join bindings: a
        // join/global-scope on the model would otherwise be silently dropped,
        // shifting every placeholder and corrupting the update.
        $update->bindings['join'] = array_merge($update->bindings['join'] ?? [], $setBindings);
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
