<?php

declare(strict_types=1);

use App\Domains\Catalog\Models\Movie;
use App\Domains\Catalog\Models\Show;
use App\Domains\Catalog\Support\CatalogImdbIds;
use Illuminate\Support\Str;

it('returns the probed ids that the catalog holds, across movies and shows', function (): void {
    // Arrange
    $movie = Movie::factory()->create(['_imdb_id' => 'tt0110912']);
    $show = Show::factory()->create(['_imdb_id' => 'tt0903747']);

    // Act
    $existing = resolve(CatalogImdbIds::class)->existing([$movie->_imdb_id, $show->_imdb_id]);

    // Assert
    expect($existing)->toBe([
        $movie->_imdb_id => true,
        $show->_imdb_id => true,
    ]);
});

it('omits a probed id that no catalog row holds', function (): void {
    // Arrange
    Movie::factory()->create(['_imdb_id' => 'tt0110912']);

    // Act
    $existing = resolve(CatalogImdbIds::class)->existing(['tt0110912', 'tt9999999']);

    // Assert
    expect($existing)->toBe(['tt0110912' => true]);
});

it('omits a catalog id that was not probed', function (): void {
    // Arrange
    $probed = Movie::factory()->create(['_imdb_id' => 'tt0110912']);
    $unprobed = Movie::factory()->create(['_imdb_id' => 'tt0068646']);

    // Act
    $existing = resolve(CatalogImdbIds::class)->existing([$probed->_imdb_id]);

    // Assert
    expect($existing)->toBe([$probed->_imdb_id => true])
        ->and($existing)->not->toHaveKey($unprobed->_imdb_id);
});

it('reads the catalog with a bounded in list, never a whole-column scan', function (): void {
    // Arrange
    Movie::factory()->create(['_imdb_id' => 'tt0110912']);
    Show::factory()->create(['_imdb_id' => 'tt0903747']);
    $idColumnSelects = fn (): array => collect(DB::getQueryLog())
        ->pluck('query')
        ->map(fn (mixed $query): string => (string) $query)
        ->filter(fn (string $query): bool => Str::startsWith($query, 'select') && Str::contains($query, '_imdb_id'))
        ->values()
        ->all();
    DB::enableQueryLog();

    // Act
    resolve(CatalogImdbIds::class)->existing(['tt0110912', 'tt0903747']);

    // Assert
    expect($idColumnSelects())->not->toBeEmpty();
    foreach ($idColumnSelects() as $query) {
        expect($query)->toContain('in (');
    }
});

it('returns an empty set for an empty probe list', function (): void {
    // Arrange
    Movie::factory()->create(['_imdb_id' => 'tt0110912']);

    // Act
    $existing = resolve(CatalogImdbIds::class)->existing([]);

    // Assert
    expect($existing)->toBe([]);
});
