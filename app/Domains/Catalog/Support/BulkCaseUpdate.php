<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Support;

use App\Domains\Catalog\Models\Movie;
use App\Domains\Catalog\Models\Show;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Expression;

final readonly class BulkCaseUpdate
{
    /**
     * @param  Builder<Movie>|Builder<Show>  $query
     * @param  array<array-key, array<string, mixed>>  $valuesById  keyed by the key column's value => [column => value]
     * @param  list<string>  $columns  the DB columns to write, in SET-clause order
     * @return list<string|int> the matched key-column values
     */
    public function handle(
        Builder $query,
        array $valuesById,
        array $columns,
        string $keyColumn = '_imdb_id',
        bool $touch = true,
    ): array {
        $matchedIds = (clone $query)
            ->whereIn($keyColumn, array_keys($valuesById))
            ->pluck($keyColumn)
            ->all();

        if ($matchedIds === []) {
            return [];
        }

        $setBindings = [];
        $set = [];

        foreach ($columns as $column) {
            $case = $this->buildCase($keyColumn, $matchedIds, fn (string|int $key): mixed => $valuesById[$key][$column]);

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
        // Opted out ($touch false), the SET entry and its binding are both skipped,
        // so the CASE bindings keep their SET-clause positions with nothing trailing.
        if ($touch) {
            $set['updated_at'] = new Expression('?');
            $setBindings[] = now();
        }

        $update = (clone $query)->toBase()->whereIn($keyColumn, $matchedIds);

        // Every SET placeholder — the CASE columns, plus updated_at when it was
        // added above — carries its binding in this one 'join' slot, in SET-clause
        // order: the grammar renders SET before WHERE, Expression values contribute
        // no bindings of their own, and prepareBindingsForUpdate() puts the join
        // slot ahead of the where bindings. Append to (never replace) any existing
        // join bindings: a join/global-scope on the model would otherwise be
        // silently dropped, shifting every placeholder and corrupting the update.
        $update->bindings['join'] = array_merge($update->bindings['join'] ?? [], $setBindings);
        $update->update($set);

        return $matchedIds;
    }

    /**
     * Build a `CASE <keyColumn> WHEN ? THEN ? ... END` expression for the matched
     * key values, with bindings in placeholder order (key, value, key, value, ...).
     *
     * @param  list<string|int>  $matchedIds
     * @param  callable(string|int): mixed  $valueFor
     * @return array{sql: string, bindings: list<mixed>}
     */
    private function buildCase(string $keyColumn, array $matchedIds, callable $valueFor): array
    {
        $sql = 'CASE '.$keyColumn;
        $bindings = [];

        foreach ($matchedIds as $key) {
            $sql .= ' WHEN ? THEN ?';
            $bindings[] = $key;
            $bindings[] = $valueFor($key);
        }

        return ['sql' => $sql.' END', 'bindings' => $bindings];
    }
}
