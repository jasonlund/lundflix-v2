<?php

declare(strict_types=1);

use App\Domains\Catalog\Enums\TitleType;

it('exposes the 7 IMDb title-type cases with their exact string backing values', function (): void {
    // Arrange
    $expected = [
        'Movie' => 'movie',
        'TvMovie' => 'tvMovie',
        'Short' => 'short',
        'TvSpecial' => 'tvSpecial',
        'Video' => 'video',
        'TvSeries' => 'tvSeries',
        'TvMiniSeries' => 'tvMiniSeries',
    ];

    // Act
    $actual = collect(TitleType::cases())
        ->mapWithKeys(fn (TitleType $case): array => [$case->name => $case->value])
        ->all();

    // Assert
    expect($actual)->toBe($expected);
});

it('treats series title types as shows', function (): void {
    // Arrange
    $seriesTypes = [TitleType::TvSeries, TitleType::TvMiniSeries];

    // Act
    $results = array_map(fn (TitleType $type): bool => $type->isShow(), $seriesTypes);

    // Assert
    expect($results)->toBe([true, true]);
});

it('does not treat single-work title types as shows', function (): void {
    // Arrange
    $nonSeriesTypes = [
        TitleType::Movie,
        TitleType::TvMovie,
        TitleType::Short,
        TitleType::TvSpecial,
        TitleType::Video,
    ];

    // Act
    $results = array_map(fn (TitleType $type): bool => $type->isShow(), $nonSeriesTypes);

    // Assert
    expect($results)->toBe([false, false, false, false, false]);
});
