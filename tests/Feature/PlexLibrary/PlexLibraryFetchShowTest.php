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
| Both walk X-Plex-Container-Start/Size pages exactly like fetchSectionItems — a
| show with more episodes than one container would otherwise return truncated, and
| ReconcilePlexEpisodes hard-deletes every episode absent from the list it is given.
|
| Fixtures (byte-exact real captures):
|   tests/Fixtures/PlexLibrary/plex/show_children_seasons.json — MediaContainer.
|     Metadata of 1 season, member type "season".
|   tests/Fixtures/PlexLibrary/plex/show_allLeaves_episodes.json — MediaContainer.
|     Metadata of 24 episodes, first member type "episode" with Guid[] carrying
|     imdb://tt0502205.
|
| The paging test has no real capture to load: this server's shows all fit in one
| container, so a multi-page allLeaves response can't be captured. It is therefore
| built here from the REAL 24 episode members of the capture above, re-enveloped
| into two synthetic MediaContainer pages carrying the offset/size/totalSize a
| container-paged Plex response returns — only the envelope is synthetic.
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

it('walks both leaves pages and concatenates every episode', function (): void {
    // Arrange
    $uri = 'https://plex.test:6022';
    $token = 'access-token-abc';
    $members = data_get(json_decode(fixtureBytes('PlexLibrary/plex/show_allLeaves_episodes.json'), true), 'MediaContainer.Metadata');
    $page = fn (array $slice, int $offset): string => (string) json_encode(['MediaContainer' => [
        'size' => count($slice),
        'totalSize' => count($members),
        'offset' => $offset,
        'Metadata' => $slice,
    ]]);
    Http::fake([
        '*/library/metadata/34112/allLeaves*' => Http::sequence()
            ->push($page(array_slice($members, 0, 12), 0))
            ->push($page(array_slice($members, 12), 12)),
    ]);

    // Act
    $episodes = resolve(PlexLibraryService::class)->fetchShowLeaves($uri, $token, '34112');

    // Assert
    expect($episodes)->toHaveCount(24)
        ->and(collect($episodes)->pluck('ratingKey')->all())->toBe(collect($members)->pluck('ratingKey')->all());
});

it('advances X-Plex-Container-Start across the two leaves page requests', function (): void {
    // Arrange
    $uri = 'https://plex.test:6022';
    $token = 'access-token-abc';
    $members = data_get(json_decode(fixtureBytes('PlexLibrary/plex/show_allLeaves_episodes.json'), true), 'MediaContainer.Metadata');
    $page = fn (array $slice, int $offset): string => (string) json_encode(['MediaContainer' => [
        'size' => count($slice),
        'totalSize' => count($members),
        'offset' => $offset,
        'Metadata' => $slice,
    ]]);
    Http::fake([
        '*/library/metadata/34112/allLeaves*' => Http::sequence()
            ->push($page(array_slice($members, 0, 12), 0))
            ->push($page(array_slice($members, 12), 12)),
    ]);

    // Act
    resolve(PlexLibraryService::class)->fetchShowLeaves($uri, $token, '34112');

    // Assert
    Http::assertSent(fn ($request): bool => Str::contains((string) $request->url(), '/library/metadata/34112/allLeaves')
        && ($request->header('X-Plex-Container-Start')[0] ?? null) === '0');
    Http::assertSent(fn ($request): bool => Str::contains((string) $request->url(), '/library/metadata/34112/allLeaves')
        && ($request->header('X-Plex-Container-Start')[0] ?? null) === '12');
});

it('requests the children page with the container size header', function (): void {
    // Arrange
    $uri = 'https://plex.test:6022';
    $token = 'access-token-abc';
    Http::fake([
        '*/library/metadata/34112/children*' => Http::response(fixtureBytes('PlexLibrary/plex/show_children_seasons.json')),
    ]);

    // Act
    resolve(PlexLibraryService::class)->fetchShowChildren($uri, $token, '34112');

    // Assert
    Http::assertSent(fn ($request): bool => ($request->header('X-Plex-Container-Start')[0] ?? null) === '0'
        && ($request->header('X-Plex-Container-Size')[0] ?? null) === '200');
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
