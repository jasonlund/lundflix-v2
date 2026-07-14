<?php

declare(strict_types=1);

use App\Domains\Download\Data\DownloadResult;
use App\Domains\Download\Enums\Codec;
use App\Domains\Download\Enums\Quality;
use App\Domains\Download\Enums\ReleaseTag;
use App\Domains\Download\Enums\Source;

it('exposes every property unchanged from direct construction', function (): void {
    // Arrange
    // enum-and-scalar DTO, no state to set up

    // Act
    $result = new DownloadResult(
        downloadId: 42,
        name: 'Some.Release.1080p.x265',
        filename: 'Some.Release.1080p.x265',
        quality: Quality::P1080,
        codec: Codec::Hevc,
        source: Source::WebDl,
        releaseTag: ReleaseTag::None,
        availability: 17,
        sizeBytes: 4_294_967_296,
        isRar: true,
    );

    // Assert
    expect($result->downloadId)->toBe(42)
        ->and($result->name)->toBe('Some.Release.1080p.x265')
        ->and($result->filename)->toBe('Some.Release.1080p.x265')
        ->and($result->quality)->toBe(Quality::P1080)
        ->and($result->codec)->toBe(Codec::Hevc)
        ->and($result->source)->toBe(Source::WebDl)
        ->and($result->availability)->toBe(17)
        ->and($result->sizeBytes)->toBe(4_294_967_296)
        ->and($result->isRar)->toBeTrue()
        ->and($result->releaseTag)->toBe(ReleaseTag::None);
});

it('hydrates from an array casting enum strings', function (): void {
    // Arrange
    $payload = [
        'downloadId' => 7,
        'name' => 'Another.Release.1080p.HEVC',
        'filename' => 'Another.Release.1080p.HEVC',
        'quality' => '1080p',
        'codec' => 'hevc',
        'source' => 'web-dl',
        'availability' => 3,
        'sizeBytes' => 2_147_483_648,
        'isRar' => false,
        'releaseTag' => 'none',
    ];

    // Act
    $result = DownloadResult::from($payload);

    // Assert
    expect($result->quality)->toBe(Quality::P1080)
        ->and($result->codec)->toBe(Codec::Hevc)
        ->and($result->source)->toBe(Source::WebDl)
        ->and($result->sizeBytes)->toBe(2_147_483_648)
        ->and($result->filename)->toBe('Another.Release.1080p.HEVC')
        ->and($result->releaseTag)->toBe(ReleaseTag::None);
});
