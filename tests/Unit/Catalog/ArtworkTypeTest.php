<?php

declare(strict_types=1);

use App\Domains\Catalog\Enums\ArtworkType;

it('exposes the 3 artwork-type cases with their exact string backing values', function (): void {
    // Arrange
    $expected = [
        'Poster' => 'poster',
        'Backdrop' => 'backdrop',
        'Logo' => 'logo',
    ];

    // Act
    $actual = collect(ArtworkType::cases())
        ->mapWithKeys(fn (ArtworkType $case): array => [$case->name => $case->value])
        ->all();

    // Assert
    expect($actual)->toBe($expected);
});

it('returns the expected default TMDB image size for each case', function (): void {
    // Arrange
    $expected = [
        'Poster' => 'w500',
        'Backdrop' => 'w1280',
        'Logo' => 'w300',
    ];

    // Act
    $actual = collect(ArtworkType::cases())
        ->mapWithKeys(fn (ArtworkType $case): array => [$case->name => $case->defaultSize()])
        ->all();

    // Assert
    expect($actual)->toBe($expected);
});

it('returns a default size within the valid TMDB size set for that image kind', function (): void {
    // Arrange
    $validSizes = [
        'Poster' => ['w92', 'w154', 'w185', 'w342', 'w500', 'w780', 'original'],
        'Backdrop' => ['w300', 'w780', 'w1280', 'original'],
        'Logo' => ['w45', 'w92', 'w154', 'w185', 'w300', 'w500', 'original'],
    ];

    // Act
    $actual = collect(ArtworkType::cases())
        ->mapWithKeys(fn (ArtworkType $case): array => [$case->name => $case->defaultSize()])
        ->all();

    // Assert
    foreach ($actual as $name => $size) {
        expect($validSizes[$name])->toContain($size);
    }
});
