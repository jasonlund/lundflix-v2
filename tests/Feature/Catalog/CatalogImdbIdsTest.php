<?php

declare(strict_types=1);

use App\Domains\Catalog\Models\Movie;
use App\Domains\Catalog\Models\Show;
use App\Domains\Catalog\Support\CatalogImdbIds;

it('includes ids from both movies and shows', function (): void {
    // Arrange
    $movie = Movie::factory()->create(['_imdb_id' => 'tt0110912']);
    $show = Show::factory()->create(['_imdb_id' => 'tt0903747']);

    // Act
    $ids = resolve(CatalogImdbIds::class)->all();

    // Assert
    expect($ids)->toHaveKey($movie->_imdb_id)
        ->and($ids)->toHaveKey($show->_imdb_id)
        ->and($ids[$movie->_imdb_id])->toBeTrue()
        ->and($ids[$show->_imdb_id])->toBeTrue();
});

it('omits rows whose imdb id is null', function (): void {
    // Arrange
    Movie::factory()->create(['_imdb_id' => null]);
    Show::factory()->create(['_imdb_id' => null]);
    $listed = Movie::factory()->create(['_imdb_id' => 'tt0110912']);

    // Act
    $ids = resolve(CatalogImdbIds::class)->all();

    // Assert
    expect($ids)->toBe([$listed->_imdb_id => true]);
});

it('reports membership for a present id and non-membership for an absent one', function (): void {
    // Arrange
    Movie::factory()->create(['_imdb_id' => 'tt0110912']);

    // Act
    $ids = resolve(CatalogImdbIds::class)->all();

    // Assert
    expect(isset($ids['tt0110912']))->toBeTrue()
        ->and(isset($ids['tt9999999']))->toBeFalse();
});
