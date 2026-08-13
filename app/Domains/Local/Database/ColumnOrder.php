<?php

declare(strict_types=1);

namespace App\Domains\Local\Database;

use App\Domains\Local\Exceptions\ColumnOrderMismatch;
use Illuminate\Support\Str;

final class ColumnOrder
{
    /**
     * Single `ALTER TABLE` that walks $targetOrder, anchoring each column after the
     * one preceding it. The first target column is never modified — nothing precedes
     * it, so it has no `AFTER` anchor and the rest chaining onto it fixes its place.
     *
     * @param  list<array{Field: string, Type: string, Collation: ?string, Null: string, Key: string, Default: ?string, Extra: string, Comment: string}>  $columns
     * @param  list<string>  $targetOrder
     *
     * @throws ColumnOrderMismatch when $targetOrder is not an exact permutation of $columns
     */
    public static function alterStatement(string $table, array $columns, array $targetOrder): string
    {
        $definitions = collect($columns)->mapWithKeys(
            fn (array $column): array => [$column['Field'] => self::definition($column)],
        );

        $missing = $definitions->keys()->diff($targetOrder)->values()->all();
        $unknown = collect($targetOrder)->diff($definitions->keys())->values()->all();

        if ($missing !== [] || $unknown !== []) {
            throw ColumnOrderMismatch::for($table, $missing, $unknown);
        }

        $clauses = collect($targetOrder)
            ->skip(1)
            ->map(fn (string $field, int $position): string => sprintf(
                'MODIFY COLUMN `%s` %s AFTER `%s`',
                $field,
                $definitions[$field],
                $targetOrder[$position - 1],
            ));

        return sprintf('ALTER TABLE `%s` %s', $table, $clauses->implode(', '));
    }

    /**
     * Rebuilds the column's DDL in MySQL's clause order:
     * `Type [COLLATE …] [NOT NULL|NULL] [DEFAULT …] [extra] [COMMENT '…']`.
     *
     * @param  array{Field: string, Type: string, Collation: ?string, Null: string, Key: string, Default: ?string, Extra: string, Comment: string}  $column
     */
    private static function definition(array $column): string
    {
        return collect([
            $column['Type'],
            $column['Collation'] === null ? null : 'COLLATE '.$column['Collation'],
            $column['Null'] === 'YES' ? 'NULL' : 'NOT NULL',
            self::defaultClause($column['Default'], $column['Extra']),
            self::extraClause($column['Extra']),
            $column['Comment'] === '' ? null : 'COMMENT '.self::quoteLiteral($column['Comment']),
        ])
            ->filter(fn (?string $clause): bool => $clause !== null)
            ->implode(' ');
    }

    /**
     * A function/expression default (`CURRENT_TIMESTAMP`) is flagged by MySQL with
     * `DEFAULT_GENERATED` in `Extra` and must stay unquoted — quoting it would make
     * it the string literal instead of the function.
     */
    private static function defaultClause(?string $default, string $extra): ?string
    {
        if ($default === null) {
            return null;
        }

        return 'DEFAULT '.(Str::contains($extra, 'DEFAULT_GENERATED')
            ? $default
            : self::quoteLiteral($default));
    }

    /**
     * `DEFAULT_GENERATED` is informational output of `SHOW FULL COLUMNS`, not valid
     * DDL — the rest of `Extra` (e.g. `on update CURRENT_TIMESTAMP`) is carried through.
     */
    private static function extraClause(string $extra): ?string
    {
        $carried = Str::trim(Str::replace('DEFAULT_GENERATED', '', $extra));

        return $carried === '' ? null : $carried;
    }

    private static function quoteLiteral(string $value): string
    {
        return "'".Str::replace(['\\', "'"], ['\\\\', "\\'"], $value)."'";
    }
}
