<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Column reordering is MySQL-only: `ALTER TABLE … MODIFY COLUMN … AFTER …` has no
 * sqlite equivalent. This suite runs sqlite `:memory:`, and RefreshDatabase replays
 * every migration — including this one — before each test, so an unguarded reorder
 * would take the whole suite down rather than fail one case. The behavior pinned
 * here is therefore the inertness itself: on a non-MySQL connection the migration
 * must run clean and touch nothing.
 *
 * The migration is an anonymous class, so `require` on its path returns the
 * instance under test (deliberately not `require_once` — each test needs its own).
 */
$migrationPath = 'migrations/2026_08_13_000000_reorder_table_columns_to_keep_timestamps_last.php';

describe('column-reorder migration non-MySQL inertness', function () use ($migrationPath): void {
    it('issues no alter statement when run on a non-MySQL connection', function () use ($migrationPath): void {
        // Arrange
        $migration = require database_path($migrationPath);
        DB::enableQueryLog();

        // Act
        $migration->up();

        // Assert
        expect(loggedStatements(fn (string $sql): bool => Str::contains($sql, 'alter table')))->toBeEmpty();
    });

    it('issues no statement at all when reversed on a non-MySQL connection', function () use ($migrationPath): void {
        // Arrange
        $migration = require database_path($migrationPath);
        DB::enableQueryLog();

        // Act
        $migration->down();

        // Assert
        expect(DB::getQueryLog())->toBe([]);
    });

    it('leaves the movies column list untouched on a non-MySQL connection', function () use ($migrationPath): void {
        // Arrange
        $migration = require database_path($migrationPath);
        $before = Schema::getColumnListing('movies');

        // Act
        $migration->up();

        // Assert
        expect(Schema::getColumnListing('movies'))->toBe($before);
    });
});
