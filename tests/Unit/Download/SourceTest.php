<?php

declare(strict_types=1);

use App\Domains\Download\Enums\Source;

it('classifies bluray, remux and bdmv tokens as Source::BluRay', function (): void {
    // Arrange
    // (enum is the subject under test; no state to set up)

    // Act
    $sources = [
        Source::fromName('Some Movie 1080p BluRay x264'),
        Source::fromName('Some Movie 2160p REMUX'),
        Source::fromName('Some Movie BDMV'),
    ];

    // Assert
    expect($sources)->toBe([Source::BluRay, Source::BluRay, Source::BluRay]);
});

it('classifies WEB-DL and WEB.DL as Source::WebDl, never mis-matched as WebRip', function (): void {
    // Arrange
    // (enum is the subject under test; no state to set up)

    // Act
    $sources = [
        Source::fromName('Some Movie 1080p WEB-DL H264'),
        Source::fromName('Some Movie 1080p WEB.DL H264'),
    ];

    // Assert
    expect($sources)->toBe([Source::WebDl, Source::WebDl]);
});

it('classifies a WEBRip token as Source::WebRip', function (): void {
    // Arrange
    // (enum is the subject under test; no state to set up)

    // Act
    $source = Source::fromName('Some Movie 1080p WEBRip x265');

    // Assert
    expect($source)->toBe(Source::WebRip);
});

it('ranks a BluRay token over a co-occurring BDRip token by first-match scan order', function (): void {
    // Arrange
    // (enum is the subject under test; no state to set up)

    // Act
    $source = Source::fromName('Some Movie 1080p BluRay BDRip x264');

    // Assert
    expect($source)->toBe(Source::BluRay);
});

it('classifies HDTV as Source::Hdtv and DVDRip as Source::DvdRip', function (): void {
    // Arrange
    // (enum is the subject under test; no state to set up)

    // Act
    $sources = [
        Source::fromName('Some Show S01E01 720p HDTV x264'),
        Source::fromName('Some Movie DVDRip XviD'),
    ];

    // Assert
    expect($sources)->toBe([Source::Hdtv, Source::DvdRip]);
});

it('classifies a bare WEB token with no -DL/-Rip separator as Source::Web', function (): void {
    // Arrange
    // (enum is the subject under test; no state to set up)

    // Act
    $sources = [
        Source::fromName('Some Movie 1080p WEB h264-ETHEL'),
        Source::fromName('Some Movie 1080p WEB x265'),
    ];

    // Assert
    expect($sources)->toBe([Source::Web, Source::Web]);
});

it('falls back to Source::Other for a telesync/cam and a junk name carrying no web token', function (): void {
    // Arrange
    // (enum is the subject under test; no state to set up)

    // Act
    $sources = [
        Source::fromName('Some Movie 2026 TELESYNC CAM'),
        Source::fromName('Some Movie no recognizable source token'),
    ];

    // Assert
    expect($sources)->toBe([Source::Other, Source::Other]);
});

it('ranks source priority BluRay before WebDl before Web before WebRip before Hdtv before BdRip before DvdRip before Other', function (): void {
    // Arrange
    // (enum is the subject under test; no state to set up)

    // Act
    $priorities = [
        Source::BluRay->priority(),
        Source::WebDl->priority(),
        Source::Web->priority(),
        Source::WebRip->priority(),
        Source::Hdtv->priority(),
        Source::BdRip->priority(),
        Source::DvdRip->priority(),
        Source::Other->priority(),
    ];

    // Assert
    expect($priorities)->toBe([7, 6, 5, 4, 3, 2, 1, 0]);
});
