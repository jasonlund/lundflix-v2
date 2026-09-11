<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Str;

describe('library:notify schedule', function (): void {
    it('schedules library:notify every five minutes without overlapping', function (): void {
        // Arrange
        $schedule = resolve(Schedule::class);

        // Act
        $event = collect($schedule->events())->first(
            fn ($e): bool => Str::endsWith($e->command ?? '', ' library:notify'),
        );

        // Assert
        expect($event)->not->toBeNull();
        expect($event->expression)->toBe('*/5 * * * *');
        expect($event->withoutOverlapping)->toBeTrue();
    });

    // A crashed run never releases its mutex, so the lock's expiry is the only thing that
    // ends the outage — the sweep runs in seconds, so a dead lock may cost one tick at most.
    it('bounds the library:notify overlap lock to ten minutes', function (): void {
        // Arrange
        $schedule = resolve(Schedule::class);

        // Act
        $event = collect($schedule->events())->first(
            fn ($e): bool => Str::endsWith($e->command ?? '', ' library:notify'),
        );

        // Assert
        expect($event)->not->toBeNull();
        expect($event->expiresAt)->toBe(10);
    });
});
