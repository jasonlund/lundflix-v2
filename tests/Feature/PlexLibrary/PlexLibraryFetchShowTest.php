<?php

declare(strict_types=1);

use App\Domains\PlexLibrary\Services\PlexLibraryService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Plex library service — show children/leaves transport slice
|--------------------------------------------------------------------------
| fetchShowChildren(uri, token, ratingKey) reads a show's season Metadata from
| /library/metadata/{rk}/children?includeGuids=1; fetchShowLeaves(uri, token,
| ratingKey) reads the flattened episode Metadata from
| /library/metadata/{rk}/allLeaves?includeGuids=1. Both take the resolved server
| uri + access token as explicit params. HTTP is faked at the wire by a
| host-agnostic path pattern keyed on the {rk}-scoped path.
|
| Fixtures (byte-exact real captures):
|   tests/Fixtures/PlexLibrary/plex/show_children_seasons.json — MediaContainer.
|     Metadata of 1 season, member type "season".
|   tests/Fixtures/PlexLibrary/plex/show_allLeaves_episodes.json — MediaContainer.
|     Metadata of 24 episodes, first member type "episode" with Guid[] carrying
|     imdb://tt0502205.
*/

it('returns the season metadata from fetchShowChildren', function (): void {
    // Arrange
    $uri = 'https://plex.test:6022';
    $token = 'access-token-abc';
    Http::fake([
        '*/library/metadata/34112/children*' => Http::response(fixtureBytes('PlexLibrary/plex/show_children_seasons.json')),
    ]);

    // Act
    $seasons = resolve(PlexLibraryService::class)->fetchShowChildren($uri, $token, '34112');

    // Assert
    expect($seasons)->toHaveCount(1)
        ->and(data_get($seasons, '0.type'))->toBe('season');
});

it('returns the flattened episode metadata with guids from fetchShowLeaves', function (): void {
    // Arrange
    $uri = 'https://plex.test:6022';
    $token = 'access-token-abc';
    Http::fake([
        '*/library/metadata/34112/allLeaves*' => Http::response(fixtureBytes('PlexLibrary/plex/show_allLeaves_episodes.json')),
    ]);

    // Act
    $episodes = resolve(PlexLibraryService::class)->fetchShowLeaves($uri, $token, '34112');

    // Assert
    expect($episodes)->toHaveCount(24)
        ->and(data_get($episodes, '0.type'))->toBe('episode')
        ->and(data_get($episodes, '0.Guid.0.id'))->toStartWith('imdb://');
});

it('requests the ratingKey-scoped children path with includeGuids', function (): void {
    // Arrange
    $uri = 'https://plex.test:6022';
    $token = 'access-token-abc';
    Http::fake([
        '*/library/metadata/34112/children*' => Http::response(fixtureBytes('PlexLibrary/plex/show_children_seasons.json')),
    ]);

    // Act
    resolve(PlexLibraryService::class)->fetchShowChildren($uri, $token, '34112');

    // Assert
    Http::assertSent(fn ($request): bool => Str::contains((string) $request->url(), '/library/metadata/34112/children')
        && Str::contains((string) $request->url(), 'includeGuids=1'));
});
