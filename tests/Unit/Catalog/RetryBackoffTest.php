<?php

declare(strict_types=1);

use App\Domains\Catalog\Support\RetryBackoff;
use Illuminate\Support\Facades\Date;

/*
|--------------------------------------------------------------------------
| RetryBackoff turns "this row has been attempted n times and still cannot be
| resolved" into the instant it becomes a candidate again. The schedule doubles
| so a permanently unresolvable row converges toward silence, and is ceilinged
| so it never stops being retried altogether — TMDB may add a crosswalk later.
|--------------------------------------------------------------------------
*/

describe('RetryBackoff schedule', function (): void {
    it('defers a first failed attempt by one day', function (): void {
        // Arrange
        Date::setTestNow('2026-07-16 12:00:00');

        // Act
        $actual = RetryBackoff::until(1);

        // Assert
        expect($actual->toDateTimeString())->toBe('2026-07-17 12:00:00');
    });

    it('doubles the interval with each further failed attempt', function (): void {
        // Arrange
        Date::setTestNow('2026-07-16 12:00:00');

        // Act
        $intervals = array_map(
            fn (int $attempts): int => (int) Date::now()->diffInDays(RetryBackoff::until($attempts)),
            [1, 2, 3, 4, 5],
        );

        // Assert
        expect($intervals)->toBe([1, 2, 4, 8, 16]);
    });

    it('stops doubling at the ceiling so a row is never retired outright', function (): void {
        // Arrange
        Date::setTestNow('2026-07-16 12:00:00');

        // Act
        $intervals = array_map(
            fn (int $attempts): int => (int) Date::now()->diffInDays(RetryBackoff::until($attempts)),
            [7, 8, 40],
        );

        // Assert
        expect($intervals)->toBe([64, 64, 64]);
    });

    it('floors an attempt count below one at the first interval', function (): void {
        // A row's counter starts at 0, so a caller that forwards the stored value
        // instead of the incremented one must not produce a sub-day interval.
        // Arrange
        Date::setTestNow('2026-07-16 12:00:00');

        // Act
        $actual = RetryBackoff::until(0);

        // Assert
        expect($actual->toDateTimeString())->toBe('2026-07-17 12:00:00');
    });
});
