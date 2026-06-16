<?php

use App\Domains\Catalog\Services\TmdbApiService;
use Tests\TestCase;

uses(TestCase::class);

it('chunks ids into groups sized by the concurrency config on exact division', function () {
    // Arrange
    config(['services.tmdb.concurrency' => 20]);
    $ids = range(1, 1000);

    // Act
    $chunks = (new TmdbApiService)->chunkIds($ids);

    // Assert
    expect($chunks)->toHaveCount(50)
        ->and(array_map('count', $chunks))->toBe(array_fill(0, 50, 20));
});

it('leaves a correct remainder in the final chunk and preserves order', function () {
    // Arrange
    config(['services.tmdb.concurrency' => 2]);
    $ids = [601, 602, 603, 604, 605];

    // Act
    $chunks = (new TmdbApiService)->chunkIds($ids);

    // Assert
    expect(array_map('array_values', $chunks))->toBe([[601, 602], [603, 604], [605]]);
});

it('derives the chunk size from config rather than a hardcoded literal', function () {
    // Arrange
    config(['services.tmdb.concurrency' => 3]);
    $ids = range(1, 7);

    // Act
    $chunks = (new TmdbApiService)->chunkIds($ids);

    // Assert
    expect(array_map('count', $chunks))->toBe([3, 3, 1]);
});
