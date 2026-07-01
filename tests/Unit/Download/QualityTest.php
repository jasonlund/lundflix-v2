<?php

declare(strict_types=1);

use App\Domains\Download\Enums\Quality;

it('resolves a download name to its quality, preferring 720 over 1080', function (): void {
    // Arrange
    // (enum is the subject under test; no state to set up)

    // Act
    $resolved = [
        Quality::fromName('Some Movie 1080p WEB-DL'),
        Quality::fromName('Some Movie 720p BluRay'),
        Quality::fromName('Some Movie 1080 720'),
    ];

    // Assert
    expect($resolved)->toBe([Quality::P1080, Quality::P720, Quality::P720]);
});

it('returns null for a name with no recognized resolution', function (): void {
    // Arrange
    // (enum is the subject under test; no state to set up)

    // Act
    $resolved = [
        Quality::fromName('Some Movie 2160p'),
        Quality::fromName('Some Movie 480p'),
        Quality::fromName('Some Movie WEB-DL'),
    ];

    // Assert
    expect($resolved)->toBe([null, null, null]);
});

it('keeps the exact resolution backing values', function (): void {
    // Arrange
    // (enum is the subject under test; no state to set up)

    // Act
    $values = [Quality::P720->value, Quality::P1080->value];

    // Assert
    expect($values)->toBe(['720p', '1080p']);
});
