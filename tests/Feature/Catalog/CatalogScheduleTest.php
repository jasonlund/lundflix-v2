<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;

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

    // Act
    $event = collect($schedule->events())->first(
        fn ($e): bool => str_contains($e->command ?? '', 'catalog:sync'),
    );

    // Assert
    expect($event)->not->toBeNull();
    expect($event->expression)->toBe('0 0,12 * * *');
    expect($event->timezone)->toBe('America/Los_Angeles');
    expect($event->withoutOverlapping)->toBeTrue();
});
