<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;

it('registers the sync:catalog command', function () {
    // Arrange
    $commands = Artisan::all();

    // Act
    $hasCommand = array_key_exists('sync:catalog', $commands);

    // Assert
    expect($hasCommand)->toBeTrue();
});

it('schedules sync:catalog at midnight and noon America/Los_Angeles without overlapping', function () {
    // Arrange
    $schedule = app(Schedule::class);

    // Act
    $event = collect($schedule->events())->first(
        fn ($e) => str_contains($e->command ?? '', 'sync:catalog'),
    );

    // Assert
    expect($event)->not->toBeNull();
    expect($event->expression)->toBe('0 0,12 * * *');
    expect($event->timezone)->toBe('America/Los_Angeles');
    expect($event->withoutOverlapping)->toBeTrue();
});
