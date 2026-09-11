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
use Illuminate\Support\Facades\Notification;
use Tests\Support\FakeArrivals;

// Every like is taken at this frozen instant and every arrival lands after it, so
// each arrived unit counts as news toward the reported total.
beforeEach(function (): void {
    Notification::fake();
    $this->travelTo(CarbonImmutable::parse('2026-03-01 12:00:00'));
});

describe('library:notify output', function (): void {
    // Each user is owed exactly one unit, so the running totals read 1 then 2 whichever
    // user is told first — the output is pinned without depending on processing order.
    it('prints the phase line, a running-total heartbeat per user notified, and Done.', function (): void {
        // Arrange
        $firstFan = User::factory()->create();
        $secondFan = User::factory()->create();
        $firstMovie = Movie::factory()->create();
        $secondMovie = Movie::factory()->create();
        resolve(LikeTitle::class)->handle($firstFan, $firstMovie);
        resolve(LikeTitle::class)->handle($secondFan, $secondMovie);
        $this->app->instance(ReportsArrivals::class, new FakeArrivals([
            new UnitArrival(new UnitRef(UnitKind::Movie, $firstMovie->id), CarbonImmutable::parse('2026-03-02 09:00:00')),
            new UnitArrival(new UnitRef(UnitKind::Movie, $secondMovie->id), CarbonImmutable::parse('2026-03-02 09:00:00')),
        ]));

        // Act & Assert
        $this->artisan('library:notify')
            ->expectsOutput('Notifying liked units…')
            ->expectsOutput('  [notify units 1]')
            ->expectsOutput('  [notify units 2]')
            ->expectsOutput('Done.')
            ->assertSuccessful();
    });

    it('still reports its zero total and closes when there is nothing to send', function (): void {
        // Arrange
        User::factory()->create();

        // Act & Assert
        $this->artisan('library:notify')
            ->expectsOutput('  [notify units 0]')
            ->expectsOutput('Done.')
            ->assertSuccessful();
    });
});
