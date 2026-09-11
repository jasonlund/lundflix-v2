<?php

declare(strict_types=1);

use App\Domains\Catalog\Data\UnitRef;
use App\Domains\Catalog\Enums\UnitKind;
use App\Domains\Catalog\Models\Movie;
use App\Domains\Identity\Models\User;
use App\Domains\Library\Actions\LikeTitle;
use App\Domains\PlexLibrary\Contracts\ReportsArrivals;
use App\Domains\PlexLibrary\Data\UnitArrival;
use Carbon\CarbonImmutable;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Notifications\Channels\DatabaseChannel;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeArrivals;

// Every like is taken at this frozen instant and every arrival lands after it, so
// no test here trips the rule that a unit already present when liked is no news.
// Notifications are deliberately not faked: the real database channel has to run
// for a failed in-app write to be observable at all.
beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-03-01 12:00:00'));
});

describe('library:notify delivery', function (): void {
    it('lands the notification in the same run, with nothing queued', function (): void {
        // Arrange
        $user = User::factory()->create();
        $movie = Movie::factory()->create();
        resolve(LikeTitle::class)->handle($user, $movie);
        $this->app->instance(ReportsArrivals::class, new FakeArrivals([
            new UnitArrival(new UnitRef(UnitKind::Movie, $movie->id), CarbonImmutable::parse('2026-03-02 09:00:00')),
        ]));
        Queue::fake();

        // Act
        $this->artisan('library:notify')->assertSuccessful();

        // Assert
        expect($user->fresh()->unreadNotifications)->toHaveCount(1);
        Queue::assertNothingPushed();
    });

    it('leaves a unit untold when its in-app write fails, so the next run tells the user', function (): void {
        // Arrange
        $user = User::factory()->create();
        $movie = Movie::factory()->create();
        resolve(LikeTitle::class)->handle($user, $movie);
        $this->app->instance(ReportsArrivals::class, new FakeArrivals([
            new UnitArrival(new UnitRef(UnitKind::Movie, $movie->id), CarbonImmutable::parse('2026-03-02 09:00:00')),
        ]));
        // ChannelManager::createDatabaseDriver() resolves the channel from the container,
        // so binding a throwing subclass fails every in-app write of the first run.
        $this->app->instance(DatabaseChannel::class, new class extends DatabaseChannel
        {
            public function send($notifiable, Notification $notification): never
            {
                throw new RuntimeException('in-app write failed');
            }
        });
        try {
            $this->artisan('library:notify')->run();
        } catch (RuntimeException) {
        }
        // The manager caches the driver it built, so dropping the binding alone would
        // leave the throwing channel in place for the second run.
        $this->app->forgetInstance(DatabaseChannel::class);
        resolve(ChannelManager::class)->forgetDrivers();

        // Act
        $this->artisan('library:notify')->assertSuccessful();

        // Assert
        expect($user->fresh()->unreadNotifications)->toHaveCount(1);
    });
});
