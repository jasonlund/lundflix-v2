<?php

declare(strict_types=1);

use App\Domains\Catalog\Data\UnitRef;
use App\Domains\Catalog\Enums\UnitKind;
use App\Domains\Catalog\Models\Episode;
use App\Domains\Catalog\Models\Movie;
use App\Domains\Catalog\Models\Show;
use App\Domains\Download\Contracts\FindsAcquirableDownloads;
use App\Domains\Download\Contracts\QueuesDownload;
use App\Domains\Download\Exceptions\DownloadRequestFailed;
use App\Domains\Download\Exceptions\InvalidDownloadCredentials;
use App\Domains\Download\Exceptions\RateLimitExceeded;
use App\Domains\Identity\Models\User;
use App\Domains\Library\Actions\LikeTitle;
use App\Domains\Library\Enums\AcquisitionStatus;
use App\Domains\Library\Models\Acquisition;
use App\Domains\PlexLibrary\Contracts\ReportsPresence;
use Illuminate\Console\Command;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Tests\Support\Library\FindsAcquirableDownloadsFake;
use Tests\Support\Library\QueuesDownloadFake;
use Tests\Support\Library\ReportsPresenceFake;

describe('library:acquire run output', function (): void {
    it('prints each phase line and its exact total, then Done.', function (): void {
        // Arrange
        $landed = Movie::factory()->create();
        $landedUnit = new UnitRef(UnitKind::Movie, $landed->id);
        Acquisition::factory()->forUnit($landedUnit)->create([
            'download_id' => 8101,
            'status' => AcquisitionStatus::Queued,
        ]);
        $liked = Movie::factory()->create();
        $likedUnit = new UnitRef(UnitKind::Movie, $liked->id);
        resolve(LikeTitle::class)->handle(User::factory()->create(), $liked);
        $this->instance(ReportsPresence::class, new ReportsPresenceFake($landedUnit));
        $this->instance(FindsAcquirableDownloads::class, new FindsAcquirableDownloadsFake([$likedUnit, 8102]));
        $this->instance(QueuesDownload::class, new QueuesDownloadFake);

        // Act
        $exitCode = Artisan::call('library:acquire');

        // The whole buffer, not substring checks: the contract is the lines AND
        // their order, and an exact match also rules out any stray extra line.
        // Assert
        expect(Artisan::output())->toBe(
            "Closing acquired units…\n"
            ."  [acquire closed 1]\n"
            ."Queuing liked units…\n"
            ."  [acquire checked 1]\n"
            ."  [acquire queued 1]\n"
            ."Done.\n",
        )
            ->and($exitCode)->toBe(Command::SUCCESS);
    });

    it('still prints all three zero totals and Done. when there is nothing to do', function (): void {
        // Arrange
        $this->instance(ReportsPresence::class, new ReportsPresenceFake);
        $this->instance(FindsAcquirableDownloads::class, new FindsAcquirableDownloadsFake);
        $this->instance(QueuesDownload::class, new QueuesDownloadFake);

        // Act
        Artisan::call('library:acquire');

        // Assert
        expect(Artisan::output())
            ->toContain('  [acquire closed 0]')
            ->toContain('  [acquire checked 0]')
            ->toContain('  [acquire queued 0]')
            ->toContain('Done.')
            ->not->toContain('failed;');
    });

    it('beats the checked count every hundred walked units before the queued total', function (): void {
        // Arrange
        $show = Show::factory()->create();
        Episode::factory()->count(250)->for($show)->create();
        resolve(LikeTitle::class)->handle(User::factory()->create(), $show);
        $this->instance(ReportsPresence::class, new ReportsPresenceFake);
        $this->instance(FindsAcquirableDownloads::class, new FindsAcquirableDownloadsFake);
        $this->instance(QueuesDownload::class, new QueuesDownloadFake);

        // Act
        Artisan::call('library:acquire');

        // No episode matches a download, so every beat counts walked units that
        // were then skipped, not units that were queued.
        // Assert
        expect(Artisan::output())->toContain(
            "Queuing liked units…\n"
            ."  [acquire checked 100]\n"
            ."  [acquire checked 200]\n"
            ."  [acquire checked 250]\n"
            ."  [acquire queued 0]\n",
        );
    });
});

describe('library:acquire fetch failures', function (): void {
    it('reports a failed download with its count and consequence and still sweeps the rest', function (): void {
        // Arrange
        $failing = Movie::factory()->create();
        $kept = Movie::factory()->create();
        $failingUnit = new UnitRef(UnitKind::Movie, $failing->id);
        $keptUnit = new UnitRef(UnitKind::Movie, $kept->id);
        $user = User::factory()->create();
        // Liked first so the sweep meets the failing download before the surviving one.
        resolve(LikeTitle::class)->handle($user, $failing);
        resolve(LikeTitle::class)->handle($user, $kept);
        $this->instance(ReportsPresence::class, new ReportsPresenceFake);
        $this->instance(FindsAcquirableDownloads::class, new FindsAcquirableDownloadsFake([$failingUnit, 8201], [$keptUnit, 8202]));
        $this->instance(QueuesDownload::class, new QueuesDownloadFake([
            8201 => DownloadRequestFailed::for('/download/8201'),
        ]));

        // Act
        Artisan::call('library:acquire');

        // Assert
        expect(Artisan::output())
            ->toContain('  [acquire queued 1]')
            ->toContain('1 fetch failed; not recorded, retried next run.')
            ->toContain('Done.')
            ->and(Acquisition::query()->forUnit($keptUnit)->first()?->download_id)->toBe(8202)
            ->and(Acquisition::query()->forUnit($failingUnit)->exists())->toBeFalse();
    });

    it('exits FAILURE when a fetch fails', function (): void {
        // Arrange
        $movie = Movie::factory()->create();
        $unit = new UnitRef(UnitKind::Movie, $movie->id);
        resolve(LikeTitle::class)->handle(User::factory()->create(), $movie);
        $this->instance(ReportsPresence::class, new ReportsPresenceFake);
        $this->instance(FindsAcquirableDownloads::class, new FindsAcquirableDownloadsFake([$unit, 8301]));
        $this->instance(QueuesDownload::class, new QueuesDownloadFake([
            8301 => DownloadRequestFailed::for('/download/8301'),
        ]));

        // Act & Assert
        $this->artisan('library:acquire')->assertExitCode(Command::FAILURE);
    });

    it('stops fetching for the rest of the run after a run-wide failure', function (Throwable $failure): void {
        // Arrange
        $first = Movie::factory()->create();
        $second = Movie::factory()->create();
        $user = User::factory()->create();
        resolve(LikeTitle::class)->handle($user, $first);
        resolve(LikeTitle::class)->handle($user, $second);
        $this->instance(ReportsPresence::class, new ReportsPresenceFake);
        $this->instance(FindsAcquirableDownloads::class, new FindsAcquirableDownloadsFake(
            [new UnitRef(UnitKind::Movie, $first->id), 8401],
            [new UnitRef(UnitKind::Movie, $second->id), 8402],
        ));
        // Both ids fail, so a run that kept fetching after the first would log a
        // second attempt rather than quietly succeed on it.
        $fetches = new QueuesDownloadFake([8401 => $failure, 8402 => $failure]);
        $this->instance(QueuesDownload::class, $fetches);

        // Act
        $exitCode = Artisan::call('library:acquire');

        // Assert
        expect($fetches->queued())->toHaveCount(1)
            ->and(Acquisition::query()->count())->toBe(0)
            ->and(Artisan::output())->toContain('2 fetches failed; not recorded, retried next run.')
            ->and($exitCode)->toBe(Command::FAILURE);
    })->with([
        'rejected credentials' => [InvalidDownloadCredentials::loginPageReturned()],
        'throttle lock contention' => [RateLimitExceeded::fromLockContention(new RuntimeException('lock'))],
    ]);
});

describe('library:acquire overlapping runs', function (): void {
    it('skips a unit another run records mid-fetch and still closes the run', function (): void {
        // Arrange
        $raced = Movie::factory()->create();
        $kept = Movie::factory()->create();
        $racedUnit = new UnitRef(UnitKind::Movie, $raced->id);
        $keptUnit = new UnitRef(UnitKind::Movie, $kept->id);
        $user = User::factory()->create();
        // Liked first so the sweep meets the raced unit before the surviving one.
        resolve(LikeTitle::class)->handle($user, $raced);
        resolve(LikeTitle::class)->handle($user, $kept);
        $this->instance(ReportsPresence::class, new ReportsPresenceFake);
        $this->instance(FindsAcquirableDownloads::class, new FindsAcquirableDownloadsFake([$racedUnit, 8501], [$keptUnit, 8502]));
        // The overlapping run records the unit after this run resolved it as
        // pending but before this run writes its own record.
        $this->instance(QueuesDownload::class, new QueuesDownloadFake(sideEffects: [
            8501 => function () use ($racedUnit): void {
                Acquisition::factory()->forUnit($racedUnit)->create([
                    'download_id' => 8501,
                    'status' => AcquisitionStatus::Queued,
                ]);
            },
        ]));

        // Act
        $exitCode = Artisan::call('library:acquire');

        // Assert
        expect($exitCode)->toBe(Command::SUCCESS)
            ->and(Artisan::output())
            ->toContain('  [acquire queued 1]')
            ->toContain('Done.')
            ->not->toContain('failed;')
            ->and(Acquisition::query()->forUnit($racedUnit)->count())->toBe(1)
            ->and(Acquisition::query()->forUnit($keptUnit)->first()?->download_id)->toBe(8502);
    });
});

describe('library:acquire registration and schedule', function (): void {
    it('registers the library:acquire command', function (): void {
        // Arrange
        $commands = Artisan::all();

        // Act
        $hasCommand = array_key_exists('library:acquire', $commands);

        // Assert
        expect($hasCommand)->toBeTrue();
    });

    it('schedules library:acquire every five minutes without overlapping, its lock expiring at four minutes', function (): void {
        // Arrange
        $schedule = resolve(Schedule::class);

        // anchored on the trailing argument so a sibling library: command name can't match
        // Act
        $event = collect($schedule->events())->first(
            fn ($e): bool => Str::endsWith($e->command ?? '', ' library:acquire'),
        );

        // Assert
        expect($event)->not->toBeNull();
        expect($event->expression)->toBe('*/5 * * * *');
        expect($event->withoutOverlapping)->toBeTrue();
        expect($event->expiresAt)->toBe(4);
    });
});
