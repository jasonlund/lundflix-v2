<?php

declare(strict_types=1);

use App\Domains\Download\Enums\Category;

it('keeps the source numeric category id as the backing value', function (): void {
    // Arrange
    // (enum is the subject under test; no state to set up)

    // Act
    $values = [Category::Movies->value, Category::Tv->value, Category::MovieX265->value];

    // Assert
    expect($values)->toBe(['72', '73', '100']);
});

it('flags an adult case and clears a non-adult case', function (): void {
    // Arrange
    // (enum is the subject under test; no state to set up)

    // Act
    $flags = [Category::Xxx->isAdult(), Category::Movies->isAdult()];

    // Assert
    expect($flags)->toBe([true, false]);
});

it('excludes every adult case from the defaults', function (): void {
    // Arrange
    $adult = array_filter(Category::cases(), fn (Category $case): bool => $case->isAdult());

    // Act
    $defaults = Category::defaults();

    // Assert
    expect($defaults)->toContain(Category::Movies);
    foreach ($adult as $case) {
        expect($defaults)->not->toContain($case);
    }
});

it('counts defaults as every case that is not adult', function (): void {
    // Arrange
    $adultCount = count(array_filter(Category::cases(), fn (Category $case): bool => $case->isAdult()));

    // Act
    $defaultCount = count(Category::defaults());

    // Assert
    expect($defaultCount)->toBe(count(Category::cases()) - $adultCount);
});
