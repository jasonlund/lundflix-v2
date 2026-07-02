<?php

declare(strict_types=1);

use App\Domains\Download\Enums\Codec;

it('classifies HEVC, x265 and h265 spellings as Codec::Hevc', function (): void {
    // Arrange
    // (enum is the subject under test; no state to set up)

    // Act
    $codecs = [
        Codec::fromName('Some Movie 1080p HEVC'),
        Codec::fromName('Some Movie x265'),
        Codec::fromName('Some Movie h.265'),
    ];

    // Assert
    expect($codecs)->toBe([Codec::Hevc, Codec::Hevc, Codec::Hevc]);
});

it('classifies x264 and h264 spellings as Codec::X264', function (): void {
    // Arrange
    // (enum is the subject under test; no state to set up)

    // Act
    $codecs = [
        Codec::fromName('Some Movie 1080p x264'),
        Codec::fromName('Some Movie h.264'),
    ];

    // Assert
    expect($codecs)->toBe([Codec::X264, Codec::X264]);
});

it('falls back to Codec::Other for a name with no recognizable codec token', function (): void {
    // Arrange
    // (enum is the subject under test; no state to set up)

    // Act
    $codec = Codec::fromName('Some Movie 1080p WEB-DL');

    // Assert
    expect($codec)->toBe(Codec::Other);
});

it('classifies a name containing x265/h265 as Hevc, never mis-matched as X264', function (): void {
    // Arrange
    // (enum is the subject under test; no state to set up)

    // Act
    $codecs = [
        Codec::fromName('Some Movie x265'),
        Codec::fromName('Some Movie h265'),
    ];

    // Assert
    expect($codecs)->toBe([Codec::Hevc, Codec::Hevc]);
});

it('ranks codec priority Hevc before X264 before Other', function (): void {
    // Arrange
    // (enum is the subject under test; no state to set up)

    // Act
    $priorities = [
        Codec::Hevc->priority(),
        Codec::X264->priority(),
        Codec::Other->priority(),
    ];

    // Assert
    expect($priorities)->toBe([0, 1, 2]);
});
