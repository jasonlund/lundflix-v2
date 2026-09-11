<?php

declare(strict_types=1);

use App\Domains\Catalog\Data\UnitRef;
use App\Domains\Catalog\Enums\UnitKind;
use App\Domains\Catalog\Models\Episode;
use App\Domains\Catalog\Models\Movie;
use App\Domains\Catalog\Models\Show;
use App\Domains\Identity\Models\User;
use App\Domains\Library\Actions\LikeTitle;
use App\Domains\Library\Actions\ToggleLikeBehavior;
use App\Domains\Library\Enums\Behavior;
use App\Domains\Library\Notifications\LikedUnitsArrived;
use App\Domains\PlexLibrary\Contracts\ReportsArrivals;
use App\Domains\PlexLibrary\Data\UnitArrival;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use Tests\Support\FakeArrivals;

/**
 * A predicate for `Notification::assertSentTo` that holds when the sent notice
 * names the unit — matched by kind and id together, since ids repeat across kinds.
 */
function namesArrivedUnit(UnitKind $kind, int $id): Closure
{
    return fn (LikedUnitsArrived $notification): bool => collect($notification->units)
        ->contains(fn (UnitRef $unit): bool => $unit->kind === $kind && $unit->id === $id);
}

// Every like is taken at this frozen instant and every arrival lands after it, so
// no test here trips the rule that a unit already present when liked is no news.
beforeEach(function (): void {
    Notification::fake();
    $this->travelTo(CarbonImmutable::parse('2026-03-01 12:00:00'));
});

describe('library:notify arrivals', function (): void {
    it('tells a user with Notify enabled when a liked movie arrives on the server', function (): void {
        // Arrange
        $user = User::factory()->create();
        $movie = Movie::factory()->create();
        resolve(LikeTitle::class)->handle($user, $movie);
        $this->app->instance(ReportsArrivals::class, new FakeArrivals([
            new UnitArrival(new UnitRef(UnitKind::Movie, $movie->id), CarbonImmutable::parse('2026-03-02 09:00:00')),
        ]));

        // Act
        $this->artisan('library:notify')->assertSuccessful();

        // Assert
        Notification::assertSentTo($user, LikedUnitsArrived::class, namesArrivedUnit(UnitKind::Movie, $movie->id));
    });

    it('tells a user with Notify enabled when an episode of a liked show arrives on the server', function (): void {
        // Arrange
        $user = User::factory()->create();
        $show = Show::factory()->create();
        $episode = Episode::factory()->for($show)->create();
        resolve(LikeTitle::class)->handle($user, $show);
        $this->app->instance(ReportsArrivals::class, new FakeArrivals([
            new UnitArrival(new UnitRef(UnitKind::Episode, $episode->id), CarbonImmutable::parse('2026-03-02 09:00:00')),
        ]));

        // Act
        $this->artisan('library:notify')->assertSuccessful();

        // Assert
        Notification::assertSentTo($user, LikedUnitsArrived::class, namesArrivedUnit(UnitKind::Episode, $episode->id));
    });

    it('tells each of two users liking the same title independently', function (): void {
        // Arrange
        $first = User::factory()->create();
        $second = User::factory()->create();
        $movie = Movie::factory()->create();
        resolve(LikeTitle::class)->handle($first, $movie);
        resolve(LikeTitle::class)->handle($second, $movie);
        $this->app->instance(ReportsArrivals::class, new FakeArrivals([
            new UnitArrival(new UnitRef(UnitKind::Movie, $movie->id), CarbonImmutable::parse('2026-03-02 09:00:00')),
        ]));

        // Act
        $this->artisan('library:notify')->assertSuccessful();

        // Assert
        Notification::assertSentTo($first, LikedUnitsArrived::class, namesArrivedUnit(UnitKind::Movie, $movie->id));
        Notification::assertSentTo($second, LikedUnitsArrived::class, namesArrivedUnit(UnitKind::Movie, $movie->id));
    });
});

describe('library:notify repeat runs', function (): void {
    it('tells a user about an arrival only once across two sweeps', function (): void {
        // Arrange
        $user = User::factory()->create();
        $movie = Movie::factory()->create();
        resolve(LikeTitle::class)->handle($user, $movie);
        $this->app->instance(ReportsArrivals::class, new FakeArrivals([
            new UnitArrival(new UnitRef(UnitKind::Movie, $movie->id), CarbonImmutable::parse('2026-03-02 09:00:00')),
        ]));
        $this->artisan('library:notify')->run();

        // Act
        $this->artisan('library:notify')->assertSuccessful();

        // Assert
        Notification::assertSentToTimes($user, LikedUnitsArrived::class, 1);
    });
});

describe('library:notify Notify switch', function (): void {
    it('never tells a user who switched Notify off', function (): void {
        // Arrange
        $user = User::factory()->create();
        $movie = Movie::factory()->create();
        $like = resolve(LikeTitle::class)->handle($user, $movie);
        resolve(ToggleLikeBehavior::class)->handle($like, Behavior::Notify);
        $this->app->instance(ReportsArrivals::class, new FakeArrivals([
            new UnitArrival(new UnitRef(UnitKind::Movie, $movie->id), CarbonImmutable::parse('2026-03-02 09:00:00')),
        ]));

        // Act
        $this->artisan('library:notify')->assertSuccessful();

        // Assert
        Notification::assertNotSentTo($user, LikedUnitsArrived::class);
    });
});
