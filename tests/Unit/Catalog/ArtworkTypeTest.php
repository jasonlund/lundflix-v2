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

it('maps a known TVDB artwork-type code to its case', function (int $code, ArtworkType $expected): void {
    // Arrange
    // dataset supplies the code and expected case

    // Act
    $actual = ArtworkType::fromTvdb($code);

    // Assert
    expect($actual)->toBe($expected);
})->with([
    'poster code 2' => [2, ArtworkType::Poster],
    'backdrop code 3' => [3, ArtworkType::Backdrop],
    'logo code 23' => [23, ArtworkType::Logo],
]);

it('returns null for an unmapped TVDB artwork-type code', function (int $code): void {
    // Arrange
    // dataset supplies the unmapped code

    // Act
    $actual = ArtworkType::fromTvdb($code);

    // Assert
    expect($actual)->toBeNull();
})->with([
    'banner 1' => 1,
    'icon 5' => 5,
    'seasonswide 6' => 6,
    'seasons 7' => 7,
    'clearart 22' => 22,
    'unknown 999' => 999,
]);
