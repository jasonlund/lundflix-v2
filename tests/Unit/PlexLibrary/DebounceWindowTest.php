<?php

declare(strict_types=1);

use App\Domains\PlexLibrary\Support\DebounceWindow;
use Tests\TestCase;

/*
 * `DebounceWindow` measures the group's arrival times against `now()`, which
 * resolves the `Date` facade, so these cases bind to the app TestCase (container
 * only — no database, hence no RefreshDatabase, no factories).
 *
 * The window lengths are the real episode numbers the feature debounces on:
 * a 300-second quiet period and a 900-second hard deadline. They are passed
 * inline rather than read from config so each case states its own arithmetic.
 *
 * Arrival instants are SYNTHETIC offsets from `now()`. They stand for the MIN and
 * MAX `created_at` of a pending group; how a caller derives them is not this
 * predicate's concern.
 */

uses(TestCase::class);

describe('isRipe() window thresholds', function (): void {
    it('is ripe when the newest arrival is older than the quiet period', function (): void {
        // Arrange
        $oldest = now()->subSeconds(600);
        $newest = now()->subSeconds(400);

        // Act
        $actual = DebounceWindow::isRipe($oldest, $newest, 300, 900);

        // Assert
        expect($actual)->toBeTrue();
    });

    it('is not ripe while the newest arrival is inside the quiet period and the oldest is inside the deadline', function (): void {
        // Arrange
        $oldest = now()->subSeconds(200);
        $newest = now()->subSeconds(60);

        // Act
        $actual = DebounceWindow::isRipe($oldest, $newest, 300, 900);

        // Assert
        expect($actual)->toBeFalse();
    });

    it('is ripe when the oldest arrival passed the hard deadline even though the newest is still inside the quiet period', function (): void {
        // Arrange
        // a show still receiving episodes never goes quiet, so the deadline has to fire
        $oldest = now()->subSeconds(1000);
        $newest = now()->subSeconds(10);

        // Act
        $actual = DebounceWindow::isRipe($oldest, $newest, 300, 900);

        // Assert
        expect($actual)->toBeTrue();
    });

    it('is ripe exactly at the quiet-period threshold', function (): void {
        // Arrange
        // time is frozen so the newest arrival sits precisely on the boundary rather
        // than a few microseconds past it — that exactness is what pins >= over >
        $this->freezeTime();
        $oldest = now()->subSeconds(400);
        $newest = now()->subSeconds(300);

        // Act
        $actual = DebounceWindow::isRipe($oldest, $newest, 300, 900);

        // Assert
        expect($actual)->toBeTrue();
    });
});
