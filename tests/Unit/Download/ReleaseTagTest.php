<?php

declare(strict_types=1);

use App\Domains\Download\Enums\ReleaseTag;

it('classifies a PROPER token, case-insensitively, as ReleaseTag::Proper', function (): void {
    // Arrange
    // (enum is the subject under test; no state to set up)

    // Act
    $tags = [
        ReleaseTag::fromName('Some.Movie.2003.PROPER.1080p.BluRay.x264-GRP'),
        ReleaseTag::fromName('some.movie.2003.proper.1080p.bluray.x264-grp'),
    ];

    // Assert
    expect($tags)->toBe([ReleaseTag::Proper, ReleaseTag::Proper]);
});

it('classifies a REPACK token as ReleaseTag::Repack', function (): void {
    // Arrange
    // (enum is the subject under test; no state to set up)

    // Act
    $tag = ReleaseTag::fromName('Some.Movie.2003.REPACK.1080p.BluRay.x264-GRP');

    // Assert
    expect($tag)->toBe(ReleaseTag::Repack);
});

it('falls back to ReleaseTag::None for a name carrying neither token', function (): void {
    // Arrange
    // (enum is the subject under test; no state to set up)

    // Act
    $tag = ReleaseTag::fromName('Some.Movie.2003.1080p.BluRay.x264-GRP');

    // Assert
    expect($tag)->toBe(ReleaseTag::None);
});

it('ranks a co-occurring PROPER over REPACK as ReleaseTag::Proper by checking Proper first', function (): void {
    // Arrange
    // (enum is the subject under test; no state to set up)

    // Act
    $tag = ReleaseTag::fromName('Some.Movie.2003.PROPER.REPACK.1080p.x264-GRP');

    // Assert
    expect($tag)->toBe(ReleaseTag::Proper);
});

it('ranks release-tag priority Proper before Repack before None', function (): void {
    // Arrange
    // (enum is the subject under test; no state to set up)

    // Act
    $priorities = [
        ReleaseTag::Proper->priority(),
        ReleaseTag::Repack->priority(),
        ReleaseTag::None->priority(),
    ];

    // Assert
    expect($priorities)->toBe([2, 1, 0]);
});
