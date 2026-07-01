<?php

declare(strict_types=1);

use App\Domains\Common\Services\PlexApiService;

it('extracts all four identifiers from Guid array', function (): void {
    // Arrange
    $metadata = [
        'Guid' => [
            ['id' => 'imdb://tt1375666'],
            ['id' => 'tmdb://27205'],
            ['id' => 'tvdb://12345'],
            ['id' => 'plex://movie/abc'],
        ],
    ];

    // Act
    $result = (new PlexApiService)->extractExternalIdentifiers($metadata);

    // Assert
    expect($result['imdb'] ?? null)->toBe('tt1375666')
        ->and($result['tmdb'] ?? null)->toBe(27205)
        ->and($result['tvdb'] ?? null)->toBe(12345)
        ->and($result['plex'] ?? null)->toBe('plex://movie/abc');
});

it('casts identifier values to their native types', function (): void {
    // Arrange
    $metadata = [
        'Guid' => [
            ['id' => 'imdb://tt1375666'],
            ['id' => 'tmdb://27205'],
            ['id' => 'tvdb://12345'],
            ['id' => 'plex://movie/abc'],
        ],
    ];

    // Act
    $result = (new PlexApiService)->extractExternalIdentifiers($metadata);

    // Assert
    expect(is_int($result['tmdb'] ?? null))->toBeTrue()
        ->and(is_int($result['tvdb'] ?? null))->toBeTrue()
        ->and(is_string($result['imdb'] ?? null))->toBeTrue()
        ->and(is_string($result['plex'] ?? null))->toBeTrue();
});

it('reads top-level guid, parentGuid and grandparentGuid', function (): void {
    // Arrange
    $metadata = [
        'guid' => 'imdb://tt100',
        'parentGuid' => 'tvdb://200',
        'grandparentGuid' => 'tmdb://300',
    ];

    // Act
    $result = (new PlexApiService)->extractExternalIdentifiers($metadata);

    // Assert
    expect($result['imdb'] ?? null)->toBe('tt100')
        ->and($result['tvdb'] ?? null)->toBe(200)
        ->and($result['tmdb'] ?? null)->toBe(300);
});

it('lets the entity own Guid win over a duplicate scheme from a top-level field', function (): void {
    // Arrange
    $metadata = [
        'Guid' => [
            ['id' => 'imdb://tt_episode'],
        ],
        'grandparentGuid' => 'imdb://tt_show',
    ];

    // Act
    $result = (new PlexApiService)->extractExternalIdentifiers($metadata);

    // Assert
    expect($result['imdb'] ?? null)->toBe('tt_episode');
});

it('does not coerce a malformed tmdb:// or tvdb:// guid to a zero identifier', function (): void {
    // Arrange
    $metadata = [
        'Guid' => [
            ['id' => 'tmdb://'],
            ['id' => 'tvdb://abc'],
        ],
    ];

    // Act
    $result = (new PlexApiService)->extractExternalIdentifiers($metadata);

    // Assert
    expect($result)->not->toHaveKey('tmdb')
        ->and($result)->not->toHaveKey('tvdb');
});

it('ignores empty, non-string and unknown-scheme identifiers', function (): void {
    // Arrange
    $metadata = [
        'Guid' => [
            ['id' => ''],
            ['id' => 123],
            ['id' => 'unknown://x'],
            ['id' => 'imdb://ok'],
        ],
    ];

    // Act
    $result = (new PlexApiService)->extractExternalIdentifiers($metadata);

    // Assert
    expect($result)->toBe(['imdb' => 'ok']);
});
