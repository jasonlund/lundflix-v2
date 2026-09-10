<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;

describe('catalog:sync scheduling', function (): void {
    it('registers the catalog:sync command', function (): void {
        // Arrange
        $commands = Artisan::all();

        // Act
        $hasCommand = array_key_exists('catalog:sync', $commands);

        // Assert
        expect($hasCommand)->toBeTrue();
    });

    it('schedules catalog:sync at midnight and noon America/Los_Angeles without overlapping', function (): void {
        // Arrange
        $schedule = resolve(Schedule::class);

        // anchored on the trailing argument so the sibling catalog:sync-imdb entry can't match
        // Act
        $event = collect($schedule->events())->first(
            fn ($e): bool => Str::endsWith($e->command ?? '', ' catalog:sync'),
        );

        // Assert
        expect($event)->not->toBeNull();
        expect($event->expression)->toBe('0 0,12 * * *');
        expect($event->timezone)->toBe('America/Los_Angeles');
        expect($event->withoutOverlapping)->toBeTrue();
        expect($event->expiresAt)->toBe(360);
    });
});

describe('catalog:seed-movies scheduling', function (): void {
    it('registers catalog:seed-movies on no schedule', function (): void {
        // The full-export scan is an operator remedy, run by hand when a marker has
        // gone stale past its cap — a schedule entry would put the 1.23M-row scan
        // back on every tick, which is the whole thing this split removes.
        // Arrange
        $schedule = resolve(Schedule::class);

        // anchored on the trailing argument, matching how the scheduled entries above
        // are found, so a sibling command name can't match
        // Act
        $event = collect($schedule->events())->first(
            fn ($e): bool => Str::endsWith($e->command ?? '', ' catalog:seed-movies'),
        );

        // Assert
        expect($event)->toBeNull();
    });
});

describe('catalog:sync-imdb scheduling', function (): void {
    it('schedules catalog:sync-imdb daily at 06:00 America/Los_Angeles without overlapping', function (): void {
        // Arrange
        $schedule = resolve(Schedule::class);

        // Act
        $event = collect($schedule->events())->first(
            fn ($e): bool => Str::endsWith($e->command ?? '', ' catalog:sync-imdb'),
        );

        // Assert
        expect($event)->not->toBeNull();
        expect($event->expression)->toBe('0 6 * * *');
        expect($event->timezone)->toBe('America/Los_Angeles');
        expect($event->withoutOverlapping)->toBeTrue();
        expect($event->expiresAt)->toBe(600);
    });
});

describe('catalog:refresh-popularity scheduling', function (): void {
    it('registers the catalog:refresh-popularity command', function (): void {
        // Arrange
        $commands = Artisan::all();

        // Act
        $hasCommand = array_key_exists('catalog:refresh-popularity', $commands);

        // Assert
        expect($hasCommand)->toBeTrue();
    });

    it('schedules catalog:refresh-popularity daily at 03:00 America/Los_Angeles without overlapping', function (): void {
        // Arrange
        $schedule = resolve(Schedule::class);

        // anchored on the trailing argument so a sibling catalog: command name can't match
        // Act
        $event = collect($schedule->events())->first(
            fn ($e): bool => Str::endsWith($e->command ?? '', ' catalog:refresh-popularity'),
        );

        // 03:00 is the midpoint between catalog:sync (00:00/12:00) and catalog:sync-imdb
        // (06:00), and it lands after TMDB publishes the day's export (08:00 UTC = 00:00 PST
        // / 01:00 PDT), so every run reads that day's export.
        // 360 matches catalog:sync's expiry: generous cover for a run measured in minutes,
        // yet dead long before the next daily tick, so a SIGKILLed run can't hold the lock
        // into the next day's run.
        // Assert
        expect($event)->not->toBeNull();
        expect($event->expression)->toBe('0 3 * * *');
        expect($event->timezone)->toBe('America/Los_Angeles');
        expect($event->withoutOverlapping)->toBeTrue();
        expect($event->expiresAt)->toBe(360);
    });
});
