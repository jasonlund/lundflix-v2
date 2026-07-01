<?php

declare(strict_types=1);

use App\Domains\Common\Services\PlexApiService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;

/*
|--------------------------------------------------------------------------
| Plex API service — search a show and gather its episodes slice
|--------------------------------------------------------------------------
| searchShowWithEpisodes($token, $externalGuid) chains searchByExternalId
| (type 2 = show): resolve the external guid, fan /library/all across online
| servers, fetch /library/metadata/{ratingKey} detail per match, then pool one
| more /library/metadata/{ratingKey}/allLeaves call per matched server to pull
| every episode. Returns one entry per server hosting the show, each carrying a
| trimmed show summary and a flat episode list.
|
| Fake-pattern ordering matters: Laravel uses the FIRST matching pattern, so the
| more specific .../allLeaves* pattern is listed BEFORE the bare
| .../library/metadata/* pattern (otherwise the latter swallows allLeaves).
|
| Fixtures (real captures unless noted):
|   metadata_matches.json — external-id match; resolves to a plex:// guid.
|   matches_empty.json    — SYNTHETIC empty body; a no-match.
|   resources.json        — only lundflix is an online server with a usable
|                           non-local uri; its best connection (non-local direct
|                           IPv4, https preferred) resolves to
|                           https://203-0-113-2.servermachineidentifier000000000.plex.direct:6022.
|   library_all.json      — /library/all match; Metadata.0.ratingKey = 34277.
|   library_metadata.json — /library/metadata/34277 DETAIL record (the show).
|   all_leaves.json       — /library/metadata/{ratingKey}/allLeaves: 3 episodes;
|                           Metadata.0 = parentIndex 9 / index 1 /
|                           "There's Something About Morty" / ratingKey "35499"
|                           (string) / duration 1333088 (int) /
|                           Media.0.videoResolution "720" (string in the fixture).
*/

it('returns each hosting server with the show and its episodes', function (): void {
    // Arrange
    Http::fake([
        '*metadata.provider.plex.tv*' => Http::response(fixtureBytes('Common/plex/metadata_matches.json')),
        '*clients.plex.tv/api/v2/resources*' => Http::response(fixtureBytes('Common/plex/resources.json')),
        'https://203-0-113-2.servermachineidentifier000000000.plex.direct:6022/library/all*' => Http::response(fixtureBytes('Common/plex/library_all.json')),
        'https://203-0-113-2.servermachineidentifier000000000.plex.direct:6022/library/metadata/*/allLeaves*' => Http::response(fixtureBytes('Common/plex/all_leaves.json')),
        'https://203-0-113-2.servermachineidentifier000000000.plex.direct:6022/library/metadata/*' => Http::response(fixtureBytes('Common/plex/library_metadata.json')),
    ]);

    // Act
    $result = resolve(PlexApiService::class)->searchShowWithEpisodes('token', 'tvdb://121361');

    // Assert
    expect($result)->toBeInstanceOf(Collection::class)
        ->and($result->count())->toBe(1)
        ->and(array_keys($result->first()))->toBe(['name', 'clientIdentifier', 'owned', 'uri', 'show', 'episodes'])
        ->and(array_keys($result->first()['show']))->toBe(['title', 'year', 'ratingKey'])
        ->and($result->first()['episodes'])->toHaveCount(3)
        ->and($result->first()['episodes'][0])->toBe([
            'season' => 9,
            'episode' => 1,
            'title' => "There's Something About Morty",
            'ratingKey' => '35499',
            'duration' => 1333088,
            'videoResolution' => '720',
        ]);
});

it('returns an empty collection when the show is not found', function (): void {
    // Only the metadata provider is faked; reaching any server host is a stray-request failure.
    // Arrange
    Http::fake([
        '*metadata.provider.plex.tv*' => Http::response(fixtureBytes('Common/plex/matches_empty.json')),
    ]);

    // Act
    $result = resolve(PlexApiService::class)->searchShowWithEpisodes('token', 'tvdb://0000000');

    // Assert
    expect($result)->toBeInstanceOf(Collection::class)
        ->and($result->isEmpty())->toBeTrue();
});

it('keeps the server with empty episodes when allLeaves fails', function (): void {
    // Arrange
    Http::fake([
        '*metadata.provider.plex.tv*' => Http::response(fixtureBytes('Common/plex/metadata_matches.json')),
        '*clients.plex.tv/api/v2/resources*' => Http::response(fixtureBytes('Common/plex/resources.json')),
        'https://203-0-113-2.servermachineidentifier000000000.plex.direct:6022/library/all*' => Http::response(fixtureBytes('Common/plex/library_all.json')),
        'https://203-0-113-2.servermachineidentifier000000000.plex.direct:6022/library/metadata/*/allLeaves*' => Http::response('', 500),
        'https://203-0-113-2.servermachineidentifier000000000.plex.direct:6022/library/metadata/*' => Http::response(fixtureBytes('Common/plex/library_metadata.json')),
    ]);

    // Act
    $result = resolve(PlexApiService::class)->searchShowWithEpisodes('token', 'tvdb://121361');

    // Assert
    expect($result->count())->toBe(1)
        ->and($result->first()['episodes'])->toBe([]);
});
