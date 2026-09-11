<?php

declare(strict_types=1);

use App\Domains\Catalog\Data\UnitRef;
use App\Domains\Catalog\Enums\UnitKind;
use App\Domains\Catalog\Models\Episode;
use App\Domains\Catalog\Models\Movie;
use App\Domains\Catalog\Models\Show;
use App\Domains\Download\Contracts\FindsAcquirableDownloads;
use App\Domains\Download\Contracts\QueuesDownload;
use App\Domains\Identity\Models\User;
use App\Domains\Library\Actions\LikeTitle;
use App\Domains\Library\Enums\AcquisitionStatus;
use App\Domains\Library\Models\Acquisition;
use App\Domains\PlexLibrary\Contracts\ReportsPresence;
use Tests\Support\Library\FindsAcquirableDownloadsFake;
use Tests\Support\Library\QueuesDownloadFake;
use Tests\Support\Library\ReportsPresenceFake;

describe('library:acquire liked movies', function (): void {
    it('records a liked acquirable movie as queued with its download id and hands that id to the fetch path', function (): void {
        // Arrange
        $movie = Movie::factory()->create();
        $unit = new UnitRef(UnitKind::Movie, $movie->id);
        resolve(LikeTitle::class)->handle(User::factory()->create(), $movie);
        $this->instance(ReportsPresence::class, new ReportsPresenceFake);
        $this->instance(FindsAcquirableDownloads::class, new FindsAcquirableDownloadsFake([$unit, 8001]));
        $fetches = new QueuesDownloadFake;
        $this->instance(QueuesDownload::class, $fetches);

        // Act
        $this->artisan('library:acquire')->assertSuccessful();

        // Assert
        $acquisition = Acquisition::query()->forUnit($unit)->first();
        expect($acquisition)->not->toBeNull()
            ->and($acquisition?->status)->toBe(AcquisitionStatus::Queued)
            ->and($acquisition?->download_id)->toBe(8001)
            ->and($fetches->queued())->toBe([8001]);
    });

    it('records and fetches a movie once however many users like it', function (): void {
        // Arrange
        $movie = Movie::factory()->create();
        $unit = new UnitRef(UnitKind::Movie, $movie->id);
        User::factory()->count(3)->create()
            ->each(fn (User $user) => resolve(LikeTitle::class)->handle($user, $movie));
        $this->instance(ReportsPresence::class, new ReportsPresenceFake);
        $this->instance(FindsAcquirableDownloads::class, new FindsAcquirableDownloadsFake([$unit, 8002]));
        $fetches = new QueuesDownloadFake;
        $this->instance(QueuesDownload::class, $fetches);

        // Act
        $this->artisan('library:acquire')->assertSuccessful();

        // Assert
        expect(Acquisition::query()->forUnit($unit)->count())->toBe(1)
            ->and($fetches->queued())->toBe([8002]);
    });

    it('never records or fetches a movie already on the server, even when a download matches it', function (): void {
        // Arrange
        $movie = Movie::factory()->create();
        $unit = new UnitRef(UnitKind::Movie, $movie->id);
        resolve(LikeTitle::class)->handle(User::factory()->create(), $movie);
        $this->instance(ReportsPresence::class, new ReportsPresenceFake($unit));
        $this->instance(FindsAcquirableDownloads::class, new FindsAcquirableDownloadsFake([$unit, 8003]));
        $fetches = new QueuesDownloadFake;
        $this->instance(QueuesDownload::class, $fetches);

        // Act
        $this->artisan('library:acquire')->assertSuccessful();

        // Assert
        expect(Acquisition::query()->count())->toBe(0)
            ->and($fetches->queued())->toBe([]);
    });

    it('neither records nor fetches a movie no download matches', function (): void {
        // Arrange
        $movie = Movie::factory()->create();
        resolve(LikeTitle::class)->handle(User::factory()->create(), $movie);
        $this->instance(ReportsPresence::class, new ReportsPresenceFake);
        $this->instance(FindsAcquirableDownloads::class, new FindsAcquirableDownloadsFake);
        $fetches = new QueuesDownloadFake;
        $this->instance(QueuesDownload::class, $fetches);

        // Act
        $this->artisan('library:acquire')->assertSuccessful();

        // Assert
        expect(Acquisition::query()->count())->toBe(0)
            ->and($fetches->queued())->toBe([]);
    });
});

describe('library:acquire liked shows', function (): void {
    it('records and fetches one acquisition per acquirable episode of a liked show', function (): void {
        // Arrange
        $show = Show::factory()->create();
        $first = new UnitRef(UnitKind::Episode, Episode::factory()->for($show)->create()->id);
        $second = new UnitRef(UnitKind::Episode, Episode::factory()->for($show)->create()->id);
        resolve(LikeTitle::class)->handle(User::factory()->create(), $show);
        $this->instance(ReportsPresence::class, new ReportsPresenceFake);
        $this->instance(FindsAcquirableDownloads::class, new FindsAcquirableDownloadsFake([$first, 8005], [$second, 8006]));
        $fetches = new QueuesDownloadFake;
        $this->instance(QueuesDownload::class, $fetches);

        // Act
        $this->artisan('library:acquire')->assertSuccessful();

        // Assert
        $firstAcquisition = Acquisition::query()->forUnit($first)->first();
        $secondAcquisition = Acquisition::query()->forUnit($second)->first();
        expect(Acquisition::query()->count())->toBe(2)
            ->and($firstAcquisition?->download_id)->toBe(8005)
            ->and($firstAcquisition?->status)->toBe(AcquisitionStatus::Queued)
            ->and($secondAcquisition?->download_id)->toBe(8006)
            ->and($secondAcquisition?->status)->toBe(AcquisitionStatus::Queued)
            ->and($fetches->queued())->toEqualCanonicalizing([8005, 8006]);
    });
});
