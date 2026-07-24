<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;

it('registers the plex:sync command', function (): void {
    // Arrange
    $commands = Artisan::all();

    // Act
    $hasCommand = array_key_exists('plex:sync', $commands);

    // Assert
    expect($hasCommand)->toBeTrue();
});

it('schedules plex:sync every five minutes without overlapping', function (): void {
    // Arrange
    $schedule = resolve(Schedule::class);

    // Act
    $event = collect($schedule->events())->first(
        fn ($e): bool => Str::contains($e->command ?? '', 'plex:sync'),
    );

    // Assert
    expect($event)->not->toBeNull();
    expect($event->expression)->toBe('*/5 * * * *');
    expect($event->withoutOverlapping)->toBeTrue();
});
