<?php

declare(strict_types=1);

use App\Domains\Common\Services\PlexApiService;
use Illuminate\Support\Facades\Http;

/*
|--------------------------------------------------------------------------
| Plex API service — per-server metadata fetches (model-free uri + token)
|--------------------------------------------------------------------------
| fetchLibraryMetadata($uri, $accessToken, $ratingKey) fetches one item's
| detail (GET {uri}/library/metadata/{ratingKey}) and returns the raw detail
| array (MediaContainer.Metadata.0) or null. fetchEpisodesForShow($uri,
| $accessToken, $showRatingKey) fetches a show's leaves (GET
| {uri}/library/metadata/{ratingKey}/allLeaves) and returns the RAW
| MediaContainer.Metadata array (NOT the mapped episode shape). An empty uri
| or token short-circuits (null / []) without any HTTP.
|
| Fixtures:
|   library_metadata.json — real capture; MediaContainer.Metadata.0 is the
|     Inception detail (ratingKey "34277", title "Inception").
|   all_leaves.json — real {uri}/library/metadata/{key}/allLeaves feed;
|     MediaContainer.Metadata holds 3 raw episode objects; first ratingKey "35499".
*/

it('returns the raw detail array for a rating key', function (): void {
    // Arrange
    $uri = 'https://srv.plex.direct:32400';
    Http::fake([
        '*srv.plex.direct*/library/metadata/34277' => Http::response(fixtureBytes('Common/plex/library_metadata.json')),
    ]);

    // Act
    $result = resolve(PlexApiService::class)->fetchLibraryMetadata($uri, 'tok', '34277');

    // Assert
    expect($result)->toBeArray()
        ->and($result['ratingKey'] ?? null)->toBe('34277')
        ->and($result['title'] ?? null)->toBe('Inception');
});

it('returns null detail for an empty uri', function (): void {
    // no fakes; any HTTP would stray-error.
    // Arrange

    // Act
    $result = resolve(PlexApiService::class)->fetchLibraryMetadata('', 'tok', '34277');

    // Assert
    expect($result)->toBeNull();
});

it('returns null detail for an empty token', function (): void {
    // Arrange
    $uri = 'https://srv.plex.direct:32400';

    // Act
    $result = resolve(PlexApiService::class)->fetchLibraryMetadata($uri, '', '34277');

    // Assert
    expect($result)->toBeNull();
});

it('returns the raw episode array for a show rating key', function (): void {
    // Arrange
    $uri = 'https://srv.plex.direct:32400';
    Http::fake([
        '*srv.plex.direct*/library/metadata/34277/allLeaves*' => Http::response(fixtureBytes('Common/plex/all_leaves.json')),
    ]);

    // Act
    $result = resolve(PlexApiService::class)->fetchEpisodesForShow($uri, 'tok', '34277');

    // Assert
    expect($result)->toHaveCount(3)
        ->and($result[0]['ratingKey'] ?? null)->toBe('35499');
});

it('returns an empty episode array for an empty uri', function (): void {
    // no fakes; any HTTP would stray-error.
    // Arrange

    // Act
    $result = resolve(PlexApiService::class)->fetchEpisodesForShow('', 'tok', '34277');

    // Assert
    expect($result)->toBe([]);
});

it('returns an empty episode array for an empty token', function (): void {
    // Arrange
    $uri = 'https://srv.plex.direct:32400';

    // Act
    $result = resolve(PlexApiService::class)->fetchEpisodesForShow($uri, '', '34277');

    // Assert
    expect($result)->toBe([]);
});
