<?php

declare(strict_types=1);

use App\Domains\Catalog\Enums\ImdbDataset;
use App\Domains\Catalog\Support\ImdbDatasetMarker;
use Illuminate\Support\Facades\Cache;

beforeEach(function (): void {
    Cache::flush();
});

describe('ImdbDatasetMarker last-modified round trip', function (): void {
    it('has no marker for a dataset that has never run', function (): void {
        // Arrange
        // empty cache, flushed in beforeEach

        // Act
        $current = resolve(ImdbDatasetMarker::class)->current(ImdbDataset::TitleBasics);

        // Assert
        expect($current)->toBeNull();
    });

    it('round-trips the raw last-modified header verbatim', function (): void {
        // Arrange
        $marker = resolve(ImdbDatasetMarker::class);

        // Act
        $marker->advance(ImdbDataset::TitleBasics, 'Tue, 12 Aug 2026 01:02:03 GMT');

        // Assert
        expect($marker->current(ImdbDataset::TitleBasics))->toBe('Tue, 12 Aug 2026 01:02:03 GMT');
    });

    it('leaves other datasets unmarked when advancing one dataset', function (): void {
        // Arrange
        $marker = resolve(ImdbDatasetMarker::class);

        // Act
        $marker->advance(ImdbDataset::TitleRatings, 'Tue, 12 Aug 2026 01:02:03 GMT');

        // Assert
        expect($marker->current(ImdbDataset::TitleBasics))->toBeNull();
        expect($marker->current(ImdbDataset::TitleRatings))->toBe('Tue, 12 Aug 2026 01:02:03 GMT');
    });

    it('overwrites a previously stored marker', function (): void {
        // Arrange
        $marker = resolve(ImdbDatasetMarker::class);
        $marker->advance(ImdbDataset::TitleAkas, 'Tue, 12 Aug 2026 01:02:03 GMT');

        // Act
        $marker->advance(ImdbDataset::TitleAkas, 'Wed, 13 Aug 2026 04:05:06 GMT');

        // Assert
        expect($marker->current(ImdbDataset::TitleAkas))->toBe('Wed, 13 Aug 2026 04:05:06 GMT');
    });
});
