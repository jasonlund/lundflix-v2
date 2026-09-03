<?php

declare(strict_types=1);

use App\Domains\Catalog\Data\SyncWindow;
use Carbon\CarbonImmutable;

describe('SyncWindow endpoint formatting', function (): void {
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
});

/**
 * SyncMarker floors `since` at now - 14 days, so a stale marker leaves the span
 * between the marker-derived start and that floor never fetched and never
 * retried. A capped window carries the pre-floor start so the sync leg can name
 * the span it is skipping instead of proceeding silently.
 */
describe('SyncWindow cap reporting', function (): void {
    it('reports not capped when constructed with no uncovered start', function (): void {
        // Arrange
        $since = CarbonImmutable::parse('2026-07-01 08:30:00');
        $until = CarbonImmutable::parse('2026-07-09 12:00:00');
        $window = new SyncWindow($since, $until);

        // Act
        $actual = $window->isCapped();

        // Assert
        expect($actual)->toBeFalse();
    });

    it('reports capped when constructed with an uncovered start', function (): void {
        // Arrange
        $since = CarbonImmutable::parse('2026-06-25 12:00:00');
        $until = CarbonImmutable::parse('2026-07-09 12:00:00');
        $window = new SyncWindow($since, $until, CarbonImmutable::parse('2026-06-15 22:45:00'));

        // Act
        $actual = $window->isCapped();

        // Assert
        expect($actual)->toBeTrue();
    });

    it('formats the uncovered start as a Y-m-d date', function (): void {
        // Arrange
        $since = CarbonImmutable::parse('2026-06-25 12:00:00');
        $until = CarbonImmutable::parse('2026-07-09 12:00:00');
        $window = new SyncWindow($since, $until, CarbonImmutable::parse('2026-06-15 22:45:00'));

        // Act
        $actual = $window->uncoveredStartDate();

        // Assert
        expect($actual)->toBe('2026-06-15');
    });

    it('returns a null uncovered start date when not capped', function (): void {
        // Arrange
        $since = CarbonImmutable::parse('2026-07-01 08:30:00');
        $until = CarbonImmutable::parse('2026-07-09 12:00:00');
        $window = new SyncWindow($since, $until);

        // Act
        $actual = $window->uncoveredStartDate();

        // Assert
        expect($actual)->toBeNull();
    });
});
