<?php

declare(strict_types=1);

use App\Domains\Local\Database\ColumnOrder;
use App\Domains\Local\Exceptions\ColumnOrderMismatch;

/**
 * `$columns` fixtures mirror the row shape MySQL's `SHOW FULL COLUMNS FROM <table>`
 * returns, drawn from the column shapes this repo really has (`bigint unsigned`,
 * `varchar(255)`, `json`, `timestamp`, `tinyint(1)`).
 *
 * @return list<array{Field: string, Type: string, Collation: ?string, Null: string, Key: string, Default: ?string, Extra: string, Comment: string}>
 */
function showFullColumns(): array
{
    return [
        ['Field' => 'id', 'Type' => 'bigint unsigned', 'Collation' => null, 'Null' => 'NO', 'Key' => 'PRI', 'Default' => null, 'Extra' => 'auto_increment', 'Comment' => ''],
        ['Field' => 'created_at', 'Type' => 'timestamp', 'Collation' => null, 'Null' => 'YES', 'Key' => '', 'Default' => null, 'Extra' => '', 'Comment' => ''],
        ['Field' => 'updated_at', 'Type' => 'timestamp', 'Collation' => null, 'Null' => 'YES', 'Key' => '', 'Default' => null, 'Extra' => '', 'Comment' => ''],
        ['Field' => 'title', 'Type' => 'varchar(255)', 'Collation' => 'utf8mb4_unicode_ci', 'Null' => 'NO', 'Key' => '', 'Default' => null, 'Extra' => '', 'Comment' => ''],
    ];
}

/**
 * The `id` row the definition-fidelity fixtures below anchor on: it leads the target
 * order, so it is never modified and the column after it is the one rebuilt.
 *
 * @return array{Field: string, Type: string, Collation: ?string, Null: string, Key: string, Default: ?string, Extra: string, Comment: string}
 */
function anchorColumn(): array
{
    return ['Field' => 'id', 'Type' => 'bigint unsigned', 'Collation' => null, 'Null' => 'NO', 'Key' => 'PRI', 'Default' => null, 'Extra' => 'auto_increment', 'Comment' => ''];
}

it('chains each moved column after the one preceding it in the target order', function (): void {
    // Arrange
    $columns = showFullColumns();
    $targetOrder = ['id', 'title', 'created_at', 'updated_at'];

    // Act
    $statement = ColumnOrder::alterStatement('movies', $columns, $targetOrder);

    // Assert
    expect($statement)
        ->toStartWith('ALTER TABLE `movies` ')
        ->toMatch('/MODIFY COLUMN `title` [^,]*AFTER `id`/')
        ->toMatch('/MODIFY COLUMN `created_at` [^,]*AFTER `title`/')
        ->toMatch('/MODIFY COLUMN `updated_at` [^,]*AFTER `created_at`/')
        ->and(substr_count($statement, 'ALTER TABLE'))->toBe(1);
});

it('emits no clause for the first column in the target order', function (): void {
    // Arrange
    // nothing precedes `id`, so it has no AFTER anchor and must never be modified
    $columns = showFullColumns();
    $targetOrder = ['id', 'title', 'created_at', 'updated_at'];

    // Act
    $statement = ColumnOrder::alterStatement('movies', $columns, $targetOrder);

    // Assert
    expect($statement)
        ->toContain('MODIFY COLUMN `title`')
        ->not->toContain('MODIFY COLUMN `id`')
        ->and(substr_count($statement, 'MODIFY COLUMN'))->toBe(3);
});

it('rejects a target order naming a column the table does not have', function (): void {
    // Arrange
    $columns = showFullColumns();
    $targetOrder = ['id', 'title', 'created_at', 'updated_at', 'released_at'];

    // Act & Assert
    expect(fn (): string => ColumnOrder::alterStatement('movies', $columns, $targetOrder))
        ->toThrow(ColumnOrderMismatch::class);
});

it('rejects a target order omitting a column the table does have', function (): void {
    // Arrange
    $columns = showFullColumns();
    $targetOrder = ['id', 'title', 'created_at'];

    // Act & Assert
    expect(fn (): string => ColumnOrder::alterStatement('movies', $columns, $targetOrder))
        ->toThrow(ColumnOrderMismatch::class);
});

it('rebuilds a nullable column with its exact type text and keeps it nullable', function (): void {
    // Arrange
    $columns = [
        anchorColumn(),
        ['Field' => '_tvdb_remoteIds', 'Type' => 'json', 'Collation' => null, 'Null' => 'YES', 'Key' => '', 'Default' => null, 'Extra' => '', 'Comment' => ''],
    ];

    // Act
    $statement = ColumnOrder::alterStatement('shows', $columns, ['id', '_tvdb_remoteIds']);

    // Assert
    expect($statement)
        ->toContain('MODIFY COLUMN `_tvdb_remoteIds` json NULL AFTER `id`')
        ->not->toContain('NOT NULL');
});

it('rebuilds a not-null column with its default as a quoted literal', function (): void {
    // Arrange
    $columns = [
        anchorColumn(),
        ['Field' => 'is_active', 'Type' => 'tinyint(1)', 'Collation' => null, 'Null' => 'NO', 'Key' => '', 'Default' => '1', 'Extra' => '', 'Comment' => ''],
    ];

    // Act
    $statement = ColumnOrder::alterStatement('movies', $columns, ['id', 'is_active']);

    // Assert
    expect($statement)
        ->toContain("MODIFY COLUMN `is_active` tinyint(1) NOT NULL DEFAULT '1' AFTER `id`");
});

it('carries an on-update extra through and emits a CURRENT_TIMESTAMP default unquoted', function (): void {
    // Arrange
    // a quoted 'CURRENT_TIMESTAMP' would be a string literal, not the function, and
    // MySQL rejects the informational DEFAULT_GENERATED token in DDL
    $columns = [
        anchorColumn(),
        ['Field' => 'updated_at', 'Type' => 'timestamp', 'Collation' => null, 'Null' => 'NO', 'Key' => '', 'Default' => 'CURRENT_TIMESTAMP', 'Extra' => 'DEFAULT_GENERATED on update CURRENT_TIMESTAMP', 'Comment' => ''],
    ];

    // Act
    $statement = ColumnOrder::alterStatement('plex_episodes', $columns, ['id', 'updated_at']);

    // Assert
    expect($statement)
        ->toContain('MODIFY COLUMN `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP AFTER `id`')
        ->not->toContain("'CURRENT_TIMESTAMP'")
        ->not->toContain('DEFAULT_GENERATED');
});

it('carries a non-default collation and a column comment through', function (): void {
    // Arrange
    $columns = [
        anchorColumn(),
        ['Field' => '_imdb_id', 'Type' => 'varchar(255)', 'Collation' => 'utf8mb4_bin', 'Null' => 'NO', 'Key' => 'UNI', 'Default' => null, 'Extra' => '', 'Comment' => 'raw source value'],
    ];

    // Act
    $statement = ColumnOrder::alterStatement('movies', $columns, ['id', '_imdb_id']);

    // Assert
    expect($statement)
        ->toContain('varchar(255) COLLATE utf8mb4_bin NOT NULL')
        ->toContain("COMMENT 'raw source value'");
});

it('backtick-quotes the table, the modified column and the AFTER anchor', function (): void {
    // Arrange
    $columns = [
        anchorColumn(),
        ['Field' => 'type', 'Type' => 'varchar(255)', 'Collation' => 'utf8mb4_unicode_ci', 'Null' => 'NO', 'Key' => '', 'Default' => null, 'Extra' => '', 'Comment' => ''],
    ];

    // Act
    $statement = ColumnOrder::alterStatement('media', $columns, ['id', 'type']);

    // Assert
    expect($statement)
        ->toStartWith('ALTER TABLE `media` ')
        ->toContain('MODIFY COLUMN `type` ')
        ->toContain(' AFTER `id`');
});
