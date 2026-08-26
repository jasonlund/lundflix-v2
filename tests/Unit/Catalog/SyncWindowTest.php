<?php

declare(strict_types=1);

use App\Domains\Catalog\Data\SyncWindow;
use Carbon\CarbonImmutable;

it('returns the since instant as a unix timestamp for the TVDB updates endpoint', function (): void {
    // Arrange
    $since = CarbonImmutable::parse('2026-07-01 08:30:00');
    $until = CarbonImmutable::parse('2026-07-09 12:00:00');
    $window = new SyncWindow($since, $until);

    // Act
    $actual = $window->sinceTimestamp();

    // Assert
    expect($actual)->toBe($since->timestamp);
});

it('formats the since instant as a Y-m-d start date for the TMDB changes endpoint', function (): void {
    // Arrange
    $since = CarbonImmutable::parse('2026-07-01 08:30:00');
    $until = CarbonImmutable::parse('2026-07-09 12:00:00');
    $window = new SyncWindow($since, $until);

    // Act
    $actual = $window->startDate();

    // Assert
    expect($actual)->toBe('2026-07-01');
});

it('formats the until instant as a Y-m-d end date for the TMDB changes endpoint', function (): void {
    // Arrange
    $since = CarbonImmutable::parse('2026-07-01 08:30:00');
    $until = CarbonImmutable::parse('2026-07-09 12:00:00');
    $window = new SyncWindow($since, $until);

    // Act
    $actual = $window->endDate();

    // Assert
    expect($actual)->toBe('2026-07-09');
});
