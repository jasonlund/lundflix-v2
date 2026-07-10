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

it('classifies an AV1 token as Codec::Av1', function (): void {
    // Arrange
    // (enum is the subject under test; no state to set up)

    // Act
    $codec = Codec::fromName('Some Movie 2160p AV1');

    // Assert
    expect($codec)->toBe(Codec::Av1);
});

it('classifies XviD and DivX spellings as Codec::Xvid', function (): void {
    // Arrange
    // (enum is the subject under test; no state to set up)

    // Act
    $codecs = [
        Codec::fromName('Some Movie XviD'),
        Codec::fromName('Some Movie DivX'),
    ];

    // Assert
    expect($codecs)->toBe([Codec::Xvid, Codec::Xvid]);
});

it('ranks AV1 over a co-occurring HEVC token by first-match scan order', function (): void {
    // Arrange
    // (enum is the subject under test; no state to set up)

    // Act
    $codec = Codec::fromName('Some Movie AV1 HEVC');

    // Assert
    expect($codec)->toBe(Codec::Av1);
});

it('ranks codec priority Av1 before Hevc before X264 before Xvid before Other', function (): void {
    // Arrange
    // (enum is the subject under test; no state to set up)

    // Act
    $priorities = [
        Codec::Av1->priority(),
        Codec::Hevc->priority(),
        Codec::X264->priority(),
        Codec::Xvid->priority(),
        Codec::Other->priority(),
    ];

    // Assert
    expect($priorities)->toBe([0, 1, 2, 3, 4]);
});
