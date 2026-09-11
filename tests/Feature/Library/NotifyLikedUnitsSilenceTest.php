<?php

declare(strict_types=1);

use App\Domains\Catalog\Data\UnitRef;
use App\Domains\Catalog\Enums\UnitKind;
use App\Domains\Catalog\Models\Movie;
use App\Domains\Identity\Models\User;
use App\Domains\Library\Actions\LikeTitle;
use App\Domains\Library\Actions\ToggleLikeBehavior;
use App\Domains\Library\Enums\Behavior;
use App\Domains\Library\Notifications\LikedUnitsArrived;
use App\Domains\PlexLibrary\Contracts\ReportsArrivals;
use App\Domains\PlexLibrary\Data\UnitArrival;
use App\Domains\PlexLibrary\Models\PlexMovie;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use Tests\Support\FakeArrivals;

/*
 * A unit is news only if it reached the server at or after the moment the like's
 * Notify behavior was last switched on: a new like stamps that moment at its
 * creation, and switching Notify off then on again re-stamps it at the toggle.
 * Each test starts on the same frozen instant, 2026-03-01 12:00:00.
 */

beforeEach(function (): void {
    Notification::fake();
    $this->travelTo(CarbonImmutable::parse('2026-03-01 12:00:00'));
});

describe('library:notify arrivals before Notify was enabled', function (): void {
    it('stays silent about a unit that arrived on the server before the like existed', function (): void {
        // Arrange
        $user = User::factory()->create();
        $movie = Movie::factory()->create();
        resolve(LikeTitle::class)->handle($user, $movie);
        $this->app->instance(ReportsArrivals::class, new FakeArrivals([
            new UnitArrival(new UnitRef(UnitKind::Movie, $movie->id), CarbonImmutable::parse('2026-02-24 09:00:00')),
        ]));

        // Act
        $this->artisan('library:notify')->assertSuccessful();

        // Assert
        Notification::assertNotSentTo($user, LikedUnitsArrived::class);
    });

    // The unit lands in the gap between switching Notify off and back on, so it is
    // after the like's creation but before the re-stamped enable moment.
    it('stays silent about a unit that arrived while Notify was switched off once it is switched back on', function (): void {
        // Arrange
        $user = User::factory()->create();
        $movie = Movie::factory()->create();
        $like = resolve(LikeTitle::class)->handle($user, $movie);
        $this->travelTo(CarbonImmutable::parse('2026-03-02 12:00:00'));
        resolve(ToggleLikeBehavior::class)->handle($like, Behavior::Notify);
        $this->travelTo(CarbonImmutable::parse('2026-03-04 12:00:00'));
        resolve(ToggleLikeBehavior::class)->handle($like, Behavior::Notify);
        $this->app->instance(ReportsArrivals::class, new FakeArrivals([
            new UnitArrival(new UnitRef(UnitKind::Movie, $movie->id), CarbonImmutable::parse('2026-03-03 09:00:00')),
        ]));

        // Act
        $this->artisan('library:notify')->assertSuccessful();

        // Assert
        Notification::assertNotSentTo($user, LikedUnitsArrived::class);
    });
});

describe('library:notify broadcast announcement stamp', function (): void {
    // The real mirror binding stays in place so the sweep reads the very rows whose
    // announced_at the server's broadcast owns; a fake would never touch them.
    it('leaves the broadcast announcement stamp exactly as it found it', function (): void {
        // Arrange
        $user = User::factory()->create();
        $unannounced = Movie::factory()->create(['_tmdb_id' => 335984]);
        $announced = Movie::factory()->create(['_tmdb_id' => 550]);
        resolve(LikeTitle::class)->handle($user, $unannounced);
        resolve(LikeTitle::class)->handle($user, $announced);
        $pendingMirror = PlexMovie::factory()->create([
            '_tmdb_id' => 335984,
            '_plex_addedAt' => CarbonImmutable::parse('2026-03-02 09:00:00'),
            'announced_at' => null,
        ]);
        $announcedMirror = PlexMovie::factory()->create([
            '_tmdb_id' => 550,
            '_plex_addedAt' => CarbonImmutable::parse('2026-03-02 09:00:00'),
            'announced_at' => '2026-03-02 09:05:00',
        ]);

        // Act
        $this->artisan('library:notify')->assertSuccessful();

        // Assert
        expect($pendingMirror->fresh()->announced_at)->toBeNull();
        expect($announcedMirror->fresh()->announced_at)->toBe('2026-03-02 09:05:00');
    });
});

describe('library:notify unliked units', function (): void {
    it('tells nobody about a unit nobody likes', function (): void {
        // Arrange
        User::factory()->create();
        $movie = Movie::factory()->create();
        $this->app->instance(ReportsArrivals::class, new FakeArrivals([
            new UnitArrival(new UnitRef(UnitKind::Movie, $movie->id), CarbonImmutable::parse('2026-03-02 09:00:00')),
        ]));

        // Act
        $this->artisan('library:notify')->assertSuccessful();

        // Assert
        Notification::assertNothingSent();
    });
});
