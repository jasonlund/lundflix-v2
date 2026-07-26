<?php

declare(strict_types=1);

use App\Domains\PlexLibrary\Support\PlexTimestamp;
use Carbon\CarbonInterface;
use Tests\TestCase;

/*
 * `PlexTimestamp` resolves the `Date` facade, so these cases bind to the app
 * TestCase (container only — no database, hence no RefreshDatabase).
 *
 * The converted epochs are real values read from `PlexLibrary/plex/episode.json`,
 * the byte-exact single-`Metadata` slice carved verbatim from a real Plex capture
 * — `addedAt` 1776560519 and `updatedAt` 1776560525 as Plex emitted them.
 *
 * The null and epoch-origin inputs are SYNTHETIC: a field Plex omits never
 * reaches a fixture as a value, and `0` is not an instant Plex sends. Both pin
 * the documented contract — absent stays null instead of collapsing to the
 * epoch origin, while a genuine `0` still converts.
 *
 * Assertions render through `->utc()` so they hold whatever timezone the
 * machine running the suite resolves the facade against.
 */

uses(TestCase::class);

it('returns null for an absent epoch rather than collapsing to the epoch origin', function (): void {
    // Arrange
    // synthetic: a field Plex omitted arrives as null, no state to set up

    // Act
    $actual = PlexTimestamp::fromEpoch(null);

    // Assert
    expect($actual)->toBeNull();
});

it('converts a real Plex addedAt epoch to the matching instant', function (): void {
    // Arrange
    $epoch = json_decode(fixtureBytes('PlexLibrary/plex/episode.json'), true)['addedAt'];

    // Act
    $actual = PlexTimestamp::fromEpoch($epoch);

    // Assert
    expect($actual)->toBeInstanceOf(CarbonInterface::class);
    expect($actual->utc()->toDateTimeString())->toBe('2026-04-19 01:01:59');
});

it('converts a real Plex updatedAt epoch to the matching instant', function (): void {
    // Arrange
    $epoch = json_decode(fixtureBytes('PlexLibrary/plex/episode.json'), true)['updatedAt'];

    // Act
    $actual = PlexTimestamp::fromEpoch($epoch);

    // Assert
    expect($actual)->toBeInstanceOf(CarbonInterface::class);
    expect($actual->utc()->toDateTimeString())->toBe('2026-04-19 01:02:05');
});

it('converts a zero epoch to the epoch origin instead of null', function (): void {
    // Arrange
    // synthetic: Plex never sends 0 — proves the null check is on null, not falsiness
    $epoch = 0;

    // Act
    $actual = PlexTimestamp::fromEpoch($epoch);

    // Assert
    expect($actual)->toBeInstanceOf(CarbonInterface::class);
    expect($actual->utc()->toDateTimeString())->toBe('1970-01-01 00:00:00');
});
