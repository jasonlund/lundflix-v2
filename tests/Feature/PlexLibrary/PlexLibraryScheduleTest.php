<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;

describe('plex:sync registration and schedule', function (): void {
    it('registers the plex:sync command', function (): void {
        // Arrange
        $commands = Artisan::all();

        // Act
        $hasCommand = array_key_exists('plex:sync', $commands);

        // Assert
        expect($hasCommand)->toBeTrue();
    });

    it('schedules plex:sync every minute without overlapping', function (): void {
        // Arrange
        $schedule = resolve(Schedule::class);

        // Act
        $event = collect($schedule->events())->first(
            fn ($e): bool => Str::contains($e->command ?? '', 'plex:sync'),
        );

        // Assert
        expect($event)->not->toBeNull();
        expect($event->expression)->toBe('* * * * *');
        expect($event->withoutOverlapping)->toBeTrue();
    });

    // A crashed run never releases its mutex, so the lock's expiry is the only thing that
    // ends the outage — Laravel's unbounded default (1440) would skip every run for a day.
    it('bounds the plex:sync overlap lock to thirty minutes', function (): void {
        // Arrange
        $schedule = resolve(Schedule::class);

        // Act
        $event = collect($schedule->events())->first(
            fn ($e): bool => Str::contains($e->command ?? '', 'plex:sync'),
        );

        // Assert
        expect($event)->not->toBeNull();
        expect($event->expiresAt)->toBe(30);
    });
});
