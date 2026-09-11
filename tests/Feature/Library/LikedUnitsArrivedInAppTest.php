<?php

declare(strict_types=1);

use App\Domains\Catalog\Data\UnitRef;
use App\Domains\Catalog\Enums\UnitKind;
use App\Domains\Catalog\Models\Episode;
use App\Domains\Catalog\Models\Movie;
use App\Domains\Catalog\Models\Show;
use App\Domains\Identity\Models\User;
use App\Domains\Library\Actions\LikeTitle;
use App\Domains\Library\Notifications\LikedUnitsArrived;
use App\Domains\PlexLibrary\Contracts\ReportsArrivals;
use App\Domains\PlexLibrary\Data\UnitArrival;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Tests\Support\FakeArrivals;

// Every like is taken at this frozen instant and every arrival lands after it, so
// no test here trips the rule that a unit already present when liked is no news.
// Notifications are deliberately not faked: the real channels run, so the stored
// row and the array mail transport show which one fired.
beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-03-01 12:00:00'));
});

describe('library:notify in-app delivery', function (): void {
    it('leaves the user one unread in-app notification of liked units arriving', function (): void {
        // Arrange
        $user = User::factory()->create();
        $movie = Movie::factory()->create(['_tmdb_title' => 'Dune']);
        resolve(LikeTitle::class)->handle($user, $movie);
        $this->app->instance(ReportsArrivals::class, new FakeArrivals([
            new UnitArrival(new UnitRef(UnitKind::Movie, $movie->id), CarbonImmutable::parse('2026-03-02 09:00:00')),
        ]));

        // Act
        $this->artisan('library:notify')->assertSuccessful();

        // Assert
        $unread = $user->fresh()->unreadNotifications;

        expect($unread)->toHaveCount(1);
        expect($unread->first()->type)->toBe(LikedUnitsArrived::class);
    });

    it('emails the user nothing', function (): void {
        // Arrange
        $user = User::factory()->create();
        $movie = Movie::factory()->create(['_tmdb_title' => 'Dune']);
        resolve(LikeTitle::class)->handle($user, $movie);
        $this->app->instance(ReportsArrivals::class, new FakeArrivals([
            new UnitArrival(new UnitRef(UnitKind::Movie, $movie->id), CarbonImmutable::parse('2026-03-02 09:00:00')),
        ]));

        // Act
        $this->artisan('library:notify')->assertSuccessful();

        // Assert
        // MAIL_MAILER=array under phpunit, so anything mailed lands in this transport.
        expect(Mail::getSymfonyTransport()->messages())->toBeEmpty();
    });
});

describe('LikedUnitsArrived in-app lines', function (): void {
    it('names a liked movie that arrived by its display title', function (): void {
        // Arrange
        $user = User::factory()->create();
        $movie = Movie::factory()->create(['_tmdb_title' => 'Dune']);
        resolve(LikeTitle::class)->handle($user, $movie);
        $this->app->instance(ReportsArrivals::class, new FakeArrivals([
            new UnitArrival(new UnitRef(UnitKind::Movie, $movie->id), CarbonImmutable::parse('2026-03-02 09:00:00')),
        ]));

        // Act
        $this->artisan('library:notify')->assertSuccessful();

        // Assert
        $unread = $user->fresh()->unreadNotifications;

        expect($unread)->toHaveCount(1);
        expect($unread->first()->data['lines'])->toContain('Dune');
    });

    it('names a liked show with the count of its newly arrived episodes', function (): void {
        // Arrange
        $user = User::factory()->create();
        $show = Show::factory()->create(['_tvdb_name' => 'Severance']);
        [$first, $second] = Episode::factory()->for($show)->count(2)->create()->all();
        resolve(LikeTitle::class)->handle($user, $show);
        $this->app->instance(ReportsArrivals::class, new FakeArrivals([
            new UnitArrival(new UnitRef(UnitKind::Episode, $first->id), CarbonImmutable::parse('2026-03-02 09:00:00')),
            new UnitArrival(new UnitRef(UnitKind::Episode, $second->id), CarbonImmutable::parse('2026-03-02 09:00:00')),
        ]));

        // Act
        $this->artisan('library:notify')->assertSuccessful();

        // Assert
        $unread = $user->fresh()->unreadNotifications;

        expect($unread)->toHaveCount(1);
        expect($unread->first()->data['lines'])->toContain('Severance: 2 new episodes');
    });

    it('reads in the singular for a show with a single newly arrived episode', function (): void {
        // Arrange
        $user = User::factory()->create();
        $show = Show::factory()->create(['_tvdb_name' => 'Severance']);
        $episode = Episode::factory()->for($show)->create();
        resolve(LikeTitle::class)->handle($user, $show);
        $this->app->instance(ReportsArrivals::class, new FakeArrivals([
            new UnitArrival(new UnitRef(UnitKind::Episode, $episode->id), CarbonImmutable::parse('2026-03-02 09:00:00')),
        ]));

        // Act
        $this->artisan('library:notify')->assertSuccessful();

        // Assert
        $unread = $user->fresh()->unreadNotifications;

        expect($unread)->toHaveCount(1);
        expect($unread->first()->data['lines'])->toContain('Severance: 1 new episode');
    });
});
