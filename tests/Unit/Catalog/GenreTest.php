<?php

declare(strict_types=1);

use App\Domains\Catalog\Enums\Genre;

it('keeps the exact IMDb backing values for hyphenated genres', function (): void {
    // Arrange
    // (enum is the subject under test; no state to set up)

    // Act
    $values = [
        Genre::FilmNoir->value,
        Genre::SciFi->value,
        Genre::RealityTv->value,
        Genre::GameShow->value,
        Genre::TalkShow->value,
    ];

    // Assert
    expect($values)->toBe([
        'Film-Noir',
        'Sci-Fi',
        'Reality-TV',
        'Game-Show',
        'Talk-Show',
    ]);
});

it('resolves a known IMDb genre and returns null for an unknown one', function (): void {
    // Arrange
    // (enum is the subject under test; no state to set up)

    // Act
    $resolved = [Genre::tryFrom('Action'), Genre::tryFrom('Bogus')];

    // Assert
    expect($resolved)->toBe([Genre::Action, null]);
});
