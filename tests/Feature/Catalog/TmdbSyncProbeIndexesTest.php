<?php

declare(strict_types=1);

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

/**
 * The indexes on $table, reduced to the column list and uniqueness.
 *
 * Reduced to columns on purpose: Schema::getIndexes() reports the grammar's
 * generated index NAME, which differs between the sqlite test database and the
 * MySQL that runs in production — so every assertion below matches on the column
 * set instead, which is the thing the query planner actually uses.
 *
 * @return Collection<int, array{columns: list<string>, unique: bool}>
 */
function tmdbProbeIndexes(string $table): Collection
{
    return collect(Schema::getIndexes($table))
        ->map(fn (array $index): array => [
            'columns' => array_values($index['columns']),
            'unique' => (bool) $index['unique'],
        ])
        ->values();
}

it('indexes movies on _tmdb_id and tmdb_synced_at together', function (): void {
    // Arrange
    // pure schema assertion — the migration chain RefreshDatabase runs is the setup

    // Act
    $indexes = tmdbProbeIndexes('movies');

    // Assert
    expect($indexes->pluck('columns')->all())->toContain(['_tmdb_id', 'tmdb_synced_at']);
});

it('indexes shows on _tmdb_id and tmdb_synced_at together', function (): void {
    // Arrange
    // pure schema assertion — the migration chain RefreshDatabase runs is the setup

    // Act
    $indexes = tmdbProbeIndexes('shows');

    // Assert
    expect($indexes->pluck('columns')->all())->toContain(['_tmdb_id', 'tmdb_synced_at']);
});

it('keeps the unique single-column _tmdb_id index on movies and shows', function (): void {
    // Arrange
    // pure schema assertion — the migration chain RefreshDatabase runs is the setup

    // Act
    $unique = collect(['movies', 'shows'])->mapWithKeys(fn (string $table): array => [
        $table => tmdbProbeIndexes($table)->where('unique', true)->pluck('columns')->all(),
    ]);

    // Assert
    expect($unique['movies'])->toContain(['_tmdb_id'])
        ->and($unique['shows'])->toContain(['_tmdb_id']);
});

it('keeps the single-column tmdb_synced_at index on movies and shows', function (): void {
    // Arrange
    // pure schema assertion — the migration chain RefreshDatabase runs is the setup

    // Act
    $nonUnique = collect(['movies', 'shows'])->mapWithKeys(fn (string $table): array => [
        $table => tmdbProbeIndexes($table)->where('unique', false)->pluck('columns')->all(),
    ]);

    // Assert
    expect($nonUnique['movies'])->toContain(['tmdb_synced_at'])
        ->and($nonUnique['shows'])->toContain(['tmdb_synced_at']);
});
