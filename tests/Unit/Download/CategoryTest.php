<?php

declare(strict_types=1);

use App\Domains\Download\Enums\Category;

it('keeps the source numeric category id as the backing value', function (): void {
    // Arrange
    // (enum is the subject under test; no state to set up)

    // Act
    $values = [Category::Movies->value, Category::Tv->value];

    // Assert
    expect($values)->toBe(['72', '73']);
});

it('holds exactly the two mother category cases', function (): void {
    // Arrange
    // (enum is the subject under test; no state to set up)

    // Act
    $cases = Category::cases();

    // Assert
    expect($cases)->toBe([Category::Movies, Category::Tv]);
});
