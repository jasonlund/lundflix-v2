<?php

declare(strict_types=1);

use App\Domains\Download\Data\DownloadResult;
use App\Domains\Download\Enums\Codec;
use App\Domains\Download\Enums\Quality;
use Carbon\CarbonImmutable;

it('exposes every property unchanged from direct construction', function (): void {
    // Arrange
    $uploadedAt = CarbonImmutable::parse('2026-06-01 12:00:00');

    // Act
    $result = new DownloadResult(
        downloadId: 42,
        name: 'Some.Release.1080p.x265',
        quality: Quality::P1080,
        codec: Codec::Hevc,
        availability: 17,
        sizeBytes: 4_294_967_296,
        isRar: true,
        uploadedAt: $uploadedAt,
    );

    // Assert
    expect($result->downloadId)->toBe(42)
        ->and($result->name)->toBe('Some.Release.1080p.x265')
        ->and($result->quality)->toBe(Quality::P1080)
        ->and($result->codec)->toBe(Codec::Hevc)
        ->and($result->availability)->toBe(17)
        ->and($result->sizeBytes)->toBe(4_294_967_296)
        ->and($result->isRar)->toBeTrue()
        ->and($result->uploadedAt)->toEqual($uploadedAt);
});

it('hydrates from an array casting enum strings and the date', function (): void {
    // Arrange
    $payload = [
        'downloadId' => 7,
        'name' => 'Another.Release.1080p.HEVC',
        'quality' => '1080p',
        'codec' => 'hevc',
        'availability' => 3,
        'sizeBytes' => 2_147_483_648,
        'isRar' => false,
        'uploadedAt' => '2026-06-01 12:00:00',
    ];

    // Act
    $result = DownloadResult::from($payload);

    // Assert
    expect($result->quality)->toBe(Quality::P1080)
        ->and($result->codec)->toBe(Codec::Hevc)
        ->and($result->uploadedAt)->toBeInstanceOf(CarbonImmutable::class)
        ->and($result->uploadedAt)->toEqual(CarbonImmutable::parse('2026-06-01 12:00:00'))
        ->and($result->sizeBytes)->toBe(2_147_483_648);
});
