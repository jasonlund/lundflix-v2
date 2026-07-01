<?php

declare(strict_types=1);

use App\Domains\Catalog\Casts\NullableDate;
use App\Domains\Catalog\Models\Show;

it('stores a blank or sentinel raw date as null instead of today', function (string $raw): void {
    // Arrange
    $cast = new NullableDate;

    // Act
    $stored = $cast->set(new Show, 'first_aired', $raw, []);

    // Assert
    expect($stored)->toBeNull();
})->with([
    'empty string' => '',
    'zero sentinel' => '0000-00-00',
]);

it('formats a real raw date to Y-m-d on write', function (): void {
    // Arrange
    $cast = new NullableDate;

    // Act
    $stored = $cast->set(new Show, 'first_aired', '2021-06-25T00:00:00Z', []);

    // Assert
    expect($stored)->toBe('2021-06-25');
});

it('stores null as null on write', function (): void {
    // Arrange
    $cast = new NullableDate;

    // Act
    $stored = $cast->set(new Show, 'first_aired', null, []);

    // Assert
    expect($stored)->toBeNull();
});
