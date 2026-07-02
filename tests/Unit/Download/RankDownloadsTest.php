<?php

declare(strict_types=1);

use App\Domains\Download\Actions\RankDownloads;
use App\Domains\Download\Data\DownloadResult;
use App\Domains\Download\Enums\Codec;
use App\Domains\Download\Enums\Quality;

/**
 * Builds a DownloadResult with fixed defaults, overriding only the fields
 * under test so each assertion isolates a single ranking/filter key.
 *
 * @param  array<string, mixed>  $overrides
 */
function makeResult(int $downloadId, array $overrides = []): DownloadResult
{
    return new DownloadResult(
        downloadId: $downloadId,
        name: $overrides['name'] ?? 'Some.Release',
        quality: array_key_exists('quality', $overrides) ? $overrides['quality'] : Quality::P1080,
        codec: $overrides['codec'] ?? Codec::Hevc,
        availability: $overrides['availability'] ?? 10,
        sizeBytes: $overrides['sizeBytes'] ?? 1_000_000,
        isRar: $overrides['isRar'] ?? false,
    );
}

it('drops results whose quality is null and keeps 720p and 1080p', function (): void {
    // Arrange
    $results = [
        makeResult(1, ['quality' => Quality::P1080]),
        makeResult(2, ['quality' => null]),
        makeResult(3, ['quality' => Quality::P720]),
    ];

    // Act
    $ranked = (new RankDownloads)->handle($results);

    // Assert
    expect(array_map(fn (DownloadResult $r): int => $r->downloadId, $ranked))
        ->toBe([1, 3]);
});

it('ranks non-rar before rar when codec and availability are equal', function (): void {
    // Arrange
    $results = [
        makeResult(1, ['isRar' => true]),
        makeResult(2, ['isRar' => false]),
    ];

    // Act
    $ranked = (new RankDownloads)->handle($results);

    // Assert
    expect(array_map(fn (DownloadResult $r): int => $r->downloadId, $ranked))
        ->toBe([2, 1]);
});

it('ranks by codec priority Hevc then X264 then Other within equal rar status', function (): void {
    // Arrange
    $results = [
        makeResult(1, ['codec' => Codec::Other]),
        makeResult(2, ['codec' => Codec::X264]),
        makeResult(3, ['codec' => Codec::Hevc]),
    ];

    // Act
    $ranked = (new RankDownloads)->handle($results);

    // Assert
    expect(array_map(fn (DownloadResult $r): int => $r->downloadId, $ranked))
        ->toBe([3, 2, 1]);
});

it('ranks by availability descending within equal rar and codec', function (): void {
    // Arrange
    $results = [
        makeResult(1, ['availability' => 5]),
        makeResult(2, ['availability' => 20]),
        makeResult(3, ['availability' => 12]),
    ];

    // Act
    $ranked = (new RankDownloads)->handle($results);

    // Assert
    expect(array_map(fn (DownloadResult $r): int => $r->downloadId, $ranked))
        ->toBe([2, 3, 1]);
});

it('orders by the full precedence of rar then codec then availability descending', function (): void {
    // Arrange
    $results = [
        makeResult(1, ['isRar' => true, 'codec' => Codec::Hevc, 'availability' => 100]),
        makeResult(2, ['isRar' => false, 'codec' => Codec::X264, 'availability' => 5]),
        makeResult(3, ['isRar' => false, 'codec' => Codec::Hevc, 'availability' => 8]),
        makeResult(4, ['isRar' => false, 'codec' => Codec::Hevc, 'availability' => 30]),
        makeResult(5, ['isRar' => true, 'codec' => Codec::X264, 'availability' => 50]),
    ];

    // Act
    $ranked = (new RankDownloads)->handle($results);

    // Assert
    expect(array_map(fn (DownloadResult $r): int => $r->downloadId, $ranked))
        ->toBe([4, 3, 2, 1, 5]);
});

it('returns an empty list for empty input', function (): void {
    // Arrange
    // no results to rank

    // Act
    $ranked = (new RankDownloads)->handle([]);

    // Assert
    expect($ranked)->toBe([]);
});

it('returns an empty list when every result is filtered out on null quality', function (): void {
    // Arrange
    $allNull = [
        makeResult(1, ['quality' => null]),
        makeResult(2, ['quality' => null]),
    ];

    // Act
    $ranked = (new RankDownloads)->handle($allNull);

    // Assert
    expect($ranked)->toBe([]);
});
