<?php

declare(strict_types=1);

use App\Domains\Download\Enums\Quality;

it('resolves each recognized resolution token to its case', function (): void {
    // Arrange
    // (enum is the subject under test; no state to set up)

    // Act
    $resolved = [
        Quality::fromName('Some Show 720p WEB-DL'),
        Quality::fromName('Some Show 720i WEB-DL'),
        Quality::fromName('Some Show 1080p WEB-DL'),
        Quality::fromName('Some Show 1080i WEB-DL'),
        Quality::fromName('Some Show 576p WEB-DL'),
        Quality::fromName('Some Show 576i WEB-DL'),
        Quality::fromName('Some Show 480p WEB-DL'),
        Quality::fromName('Some Show 480i WEB-DL'),
    ];

    // Assert
    expect($resolved)->toBe([
        Quality::P720,
        Quality::I720,
        Quality::P1080,
        Quality::I1080,
        Quality::P576,
        Quality::I576,
        Quality::P480,
        Quality::I480,
    ]);
});

it('returns null for an excluded 2160p token or a no-resolution name', function (): void {
    // Arrange
    // (enum is the subject under test; no state to set up)

    // Act
    $resolved = [
        Quality::fromName('Some Movie 2160p'),
        Quality::fromName('Some Movie DVDRip'),
    ];

    // Assert
    expect($resolved)->toBe([null, null]);
});

it('resolves the highest-priority token when several co-occur', function (): void {
    // Arrange
    // (enum is the subject under test; no state to set up)

    // Act
    $resolved = Quality::fromName('Show 1080p 720p');

    // Assert
    expect($resolved)->toBe(Quality::P720);
});

it('ranks the resolution ladder in strict descending priority', function (): void {
    // Arrange
    // (enum is the subject under test; no state to set up)

    // Act
    $descends = Quality::P720->priority() > Quality::I720->priority()
        && Quality::I720->priority() > Quality::P1080->priority()
        && Quality::P1080->priority() > Quality::I1080->priority()
        && Quality::I1080->priority() > Quality::P576->priority()
        && Quality::P576->priority() > Quality::I576->priority()
        && Quality::I576->priority() > Quality::P480->priority()
        && Quality::P480->priority() > Quality::I480->priority()
        && Quality::I480->priority() > PHP_INT_MIN;

    // Assert
    expect($descends)->toBeTrue();
});

it('sorts a shuffled mixed list highest-priority first with null last', function (): void {
    // Arrange
    $qualities = [
        Quality::I480,
        Quality::P1080,
        null,
        Quality::P576,
        Quality::I720,
        Quality::P480,
        Quality::P720,
        Quality::I1080,
        Quality::I576,
    ];

    // Act
    usort(
        $qualities,
        fn (?Quality $a, ?Quality $b): int => ($b?->priority() ?? PHP_INT_MIN) <=> ($a?->priority() ?? PHP_INT_MIN),
    );

    // Assert
    expect($qualities)->toBe([
        Quality::P720,
        Quality::I720,
        Quality::P1080,
        Quality::I1080,
        Quality::P576,
        Quality::I576,
        Quality::P480,
        Quality::I480,
        null,
    ]);
});

it('keeps the exact resolution backing values', function (): void {
    // Arrange
    // (enum is the subject under test; no state to set up)

    // Act
    $values = [
        Quality::P720->value,
        Quality::I720->value,
        Quality::P1080->value,
        Quality::I1080->value,
        Quality::P576->value,
        Quality::I576->value,
        Quality::P480->value,
        Quality::I480->value,
    ];

    // Assert
    expect($values)->toBe(['720p', '720i', '1080p', '1080i', '576p', '576i', '480p', '480i']);
});
