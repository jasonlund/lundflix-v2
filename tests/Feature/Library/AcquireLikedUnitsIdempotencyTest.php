<?php

declare(strict_types=1);

use App\Domains\Catalog\Data\UnitRef;
use App\Domains\Catalog\Enums\UnitKind;
use App\Domains\Catalog\Models\Movie;
use App\Domains\Download\Contracts\FindsAcquirableDownloads;
use App\Domains\Download\Contracts\QueuesDownload;
use App\Domains\Identity\Models\User;
use App\Domains\Library\Actions\LikeTitle;
use App\Domains\Library\Actions\ToggleLikeBehavior;
use App\Domains\Library\Enums\AcquisitionStatus;
use App\Domains\Library\Enums\Behavior;
use App\Domains\Library\Models\Acquisition;
use App\Domains\PlexLibrary\Contracts\ReportsPresence;
use Tests\Support\Library\FindsAcquirableDownloadsFake;
use Tests\Support\Library\QueuesDownloadFake;
use Tests\Support\Library\ReportsPresenceFake;

describe('library:acquire recorded units', function (): void {
    it('never fetches a unit that already has a record again', function (): void {
        // Arrange
        $movie = Movie::factory()->create();
        $unit = new UnitRef(UnitKind::Movie, $movie->id);
        resolve(LikeTitle::class)->handle(User::factory()->create(), $movie);
        Acquisition::factory()->forUnit($unit)->create([
            'download_id' => 8007,
            'status' => AcquisitionStatus::Queued,
        ]);
        $this->instance(ReportsPresence::class, new ReportsPresenceFake);
        $this->instance(FindsAcquirableDownloads::class, new FindsAcquirableDownloadsFake([$unit, 8007]));
        $fetches = new QueuesDownloadFake;
        $this->instance(QueuesDownload::class, $fetches);

        // Act
        $this->artisan('library:acquire')->assertSuccessful();

        // Assert
        expect($fetches->queued())->toBe([])
            ->and(Acquisition::query()->forUnit($unit)->count())->toBe(1);
    });

    it('closes a queued unit as acquired once it appears on the server', function (): void {
        // Arrange
        $movie = Movie::factory()->create();
        $unit = new UnitRef(UnitKind::Movie, $movie->id);
        resolve(LikeTitle::class)->handle(User::factory()->create(), $movie);
        $this->instance(ReportsPresence::class, new ReportsPresenceFake);
        $this->instance(FindsAcquirableDownloads::class, new FindsAcquirableDownloadsFake([$unit, 8008]));
        $fetches = new QueuesDownloadFake;
        $this->instance(QueuesDownload::class, $fetches);
        // The first run is what records the unit as queued; the server then gains
        // it before the run under test, so the world changes between the two.
        $this->artisan('library:acquire')->assertSuccessful();
        $this->instance(ReportsPresence::class, new ReportsPresenceFake($unit));

        // Act
        $this->artisan('library:acquire')->assertSuccessful();

        // Assert
        expect(Acquisition::query()->forUnit($unit)->first()?->status)->toBe(AcquisitionStatus::Acquired)
            ->and($fetches->queued())->toBe([8008]);
    });
});

describe('library:acquire skipped likes', function (): void {
    it('neither records nor fetches a liked movie whose acquire behavior is off', function (): void {
        // Arrange
        $movie = Movie::factory()->create();
        $unit = new UnitRef(UnitKind::Movie, $movie->id);
        $like = resolve(LikeTitle::class)->handle(User::factory()->create(), $movie);
        resolve(ToggleLikeBehavior::class)->handle($like, Behavior::Acquire);
        $this->instance(ReportsPresence::class, new ReportsPresenceFake);
        $this->instance(FindsAcquirableDownloads::class, new FindsAcquirableDownloadsFake([$unit, 8009]));
        $fetches = new QueuesDownloadFake;
        $this->instance(QueuesDownload::class, $fetches);

        // Act
        $this->artisan('library:acquire')->assertSuccessful();

        // Assert
        expect(Acquisition::query()->count())->toBe(0)
            ->and($fetches->queued())->toBe([]);
    });

    it('skips a like whose title no longer exists and still sweeps the rest', function (): void {
        // Arrange
        $gone = Movie::factory()->create();
        $kept = Movie::factory()->create();
        $goneUnit = new UnitRef(UnitKind::Movie, $gone->id);
        $keptUnit = new UnitRef(UnitKind::Movie, $kept->id);
        $user = User::factory()->create();
        // Liked first so the sweep meets the missing title before the surviving one.
        resolve(LikeTitle::class)->handle($user, $gone);
        resolve(LikeTitle::class)->handle($user, $kept);
        // The like outlives its title: likes.likeable is a morph column with no
        // foreign key, so deleting the movie leaves the like row behind.
        $gone->delete();
        $this->instance(ReportsPresence::class, new ReportsPresenceFake);
        $this->instance(FindsAcquirableDownloads::class, new FindsAcquirableDownloadsFake([$goneUnit, 8010], [$keptUnit, 8011]));
        $fetches = new QueuesDownloadFake;
        $this->instance(QueuesDownload::class, $fetches);

        // Act
        $this->artisan('library:acquire')->assertSuccessful();

        // Assert
        expect(Acquisition::query()->forUnit($keptUnit)->first()?->download_id)->toBe(8011)
            ->and(Acquisition::query()->forUnit($goneUnit)->exists())->toBeFalse()
            ->and($fetches->queued())->toBe([8011]);
    });
});
