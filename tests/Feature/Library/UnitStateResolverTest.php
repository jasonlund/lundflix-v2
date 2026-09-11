<?php

declare(strict_types=1);

use App\Domains\Catalog\Data\UnitRef;
use App\Domains\Catalog\Enums\UnitKind;
use App\Domains\Download\Contracts\FindsAcquirableDownloads;
use App\Domains\Library\Enums\Acquirability;
use App\Domains\Library\Enums\AcquisitionStatus;
use App\Domains\Library\Models\Acquisition;
use App\Domains\Library\Services\UnitStateResolver;
use App\Domains\PlexLibrary\Contracts\ReportsPresence;
use Tests\Support\Library\FindsAcquirableDownloadsFake;
use Tests\Support\Library\ReportsPresenceFake;

describe('UnitStateResolver::resolve() acquirability', function (): void {
    it('resolves a unit on the server as present even when a download also matches it', function (): void {
        // Arrange
        $unit = new UnitRef(UnitKind::Movie, 101);
        $this->instance(ReportsPresence::class, new ReportsPresenceFake($unit));
        $this->instance(FindsAcquirableDownloads::class, new FindsAcquirableDownloadsFake([$unit, 7001]));

        // Act
        $state = resolve(UnitStateResolver::class)->resolve($unit);

        // Assert
        expect($state->acquirability)->toBe(Acquirability::Present)
            ->and($state->acquisition)->toBeNull();
    });

    it('resolves a unit absent from the server with a matching download as acquirable, carrying its id', function (): void {
        // Arrange
        $unit = new UnitRef(UnitKind::Movie, 102);
        $this->instance(ReportsPresence::class, new ReportsPresenceFake);
        $this->instance(FindsAcquirableDownloads::class, new FindsAcquirableDownloadsFake([$unit, 7002]));

        // Act
        $state = resolve(UnitStateResolver::class)->resolve($unit);

        // Assert
        expect($state->acquirability)->toBe(Acquirability::Acquirable)
            ->and($state->downloadId)->toBe(7002)
            ->and($state->acquisition)->toBeNull();
    });

    it('resolves a unit absent from the server with no matching download as unmatched', function (): void {
        // Arrange
        $unit = new UnitRef(UnitKind::Movie, 103);
        $this->instance(ReportsPresence::class, new ReportsPresenceFake);
        $this->instance(FindsAcquirableDownloads::class, new FindsAcquirableDownloadsFake);

        // Act
        $state = resolve(UnitStateResolver::class)->resolve($unit);

        // Assert
        expect($state->acquirability)->toBe(Acquirability::Unmatched)
            ->and($state->downloadId)->toBeNull()
            ->and($state->acquisition)->toBeNull();
    });
});

describe('UnitStateResolver::resolve() acquisition status', function (): void {
    it('reports the status of an acquisition record already in flight for the unit', function (): void {
        // Arrange
        $unit = new UnitRef(UnitKind::Movie, 104);
        $this->instance(ReportsPresence::class, new ReportsPresenceFake);
        $this->instance(FindsAcquirableDownloads::class, new FindsAcquirableDownloadsFake([$unit, 7004]));
        Acquisition::factory()->forUnit($unit)->create([
            'download_id' => 7004,
            'status' => AcquisitionStatus::Queued,
        ]);

        // Act
        $state = resolve(UnitStateResolver::class)->resolve($unit);

        // Assert
        expect($state->acquisition)->toBe(AcquisitionStatus::Queued);
    });
});
