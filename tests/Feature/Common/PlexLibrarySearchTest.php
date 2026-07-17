<?php

declare(strict_types=1);

use App\Domains\Common\Services\PlexApiService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;

/*
|--------------------------------------------------------------------------
| Plex API service — resolve external GUID + pooled library search slice
|--------------------------------------------------------------------------
| resolvePlexGuid($token, $externalGuid, $type) hits Plex's metadata provider
| (GET metadata.provider.plex.tv/library/metadata/matches) to turn an external
| id (e.g. imdb://tt1375666) into a canonical plex:// guid.
|
| searchByExternalId($token, $externalGuid, $type) chains that resolution, then
| fans out across the user's online servers (pooled): per server it queries
| {uri}/library/all?guid= for a ratingKey and follows up with
| {uri}/library/metadata/{ratingKey} for the full DETAIL record, tolerating a
| down server in the pool.
|
| Fixtures:
|   metadata_matches.json — real movie match; Metadata.0.guid =
|     plex://movie/5d77685333f255001e852e11.
|   matches_empty.json — SYNTHETIC empty body ({"MediaContainer":{"size":0}});
|     a no-match the real provider can't be made to emit on demand.
|   resources.json — real capture; the one online server is lundflix, whose best
|     connection (non-local direct IPv4, https preferred) resolves to
|     https://203-0-113-2.servermachineidentifier000000000.plex.direct:6022.
|   library_all.json — real {uri}/library/all match; Metadata.0.ratingKey = 34277,
|     a lean record (no Guid/Media/Producer/Rating).
|   library_metadata.json — real {uri}/library/metadata/34277 DETAIL record;
|     carries detail-only keys (Guid, Media, …) absent from library_all.
|   resources_two_servers.json — SYNTHETIC pool of two healthy-looking servers
|     (good-cid @ good-9.plex.direct, bad-cid @ bad-9.plex.direct) so one can be
|     faked down; a multi-server pool real data doesn't conveniently provide.
*/

it('resolves an external id to a plex guid', function (): void {
    // Arrange
    Http::fake([
        'https://metadata.provider.plex.tv/library/metadata/matches*' => Http::response(fixtureBytes('Common/plex/metadata_matches.json')),
    ]);

    // Act
    $guid = resolve(PlexApiService::class)->resolvePlexGuid('token', 'imdb://tt1375666', 1);

    // Assert
    expect($guid)->toBe('plex://movie/5d77685333f255001e852e11');
});

it('returns null when the external id has no match', function (): void {
    // Arrange
    Http::fake([
        'https://metadata.provider.plex.tv/library/metadata/matches*' => Http::response(fixtureBytes('Common/plex/matches_empty.json')),
    ]);

    // Act
    $guid = resolve(PlexApiService::class)->resolvePlexGuid('token', 'imdb://tt0000000', 1);

    // Assert
    expect($guid)->toBeNull();
});

it('returns an empty collection when the guid does not resolve', function (): void {
    // Only the metadata provider is faked; reaching any server host is a stray-request failure.
    // Arrange
    Http::fake([
        'https://metadata.provider.plex.tv/library/metadata/matches*' => Http::response(fixtureBytes('Common/plex/matches_empty.json')),
    ]);

    // Act
    $result = resolve(PlexApiService::class)->searchByExternalId('token', 'imdb://tt0000000', 1);

    // Assert
    expect($result)->toBeInstanceOf(Collection::class)
        ->and($result->isEmpty())->toBeTrue();
});

it('returns the matched server carrying detail metadata', function (): void {
    // Arrange
    Http::fake([
        'https://metadata.provider.plex.tv/library/metadata/matches*' => Http::response(fixtureBytes('Common/plex/metadata_matches.json')),
        'https://clients.plex.tv/api/v2/resources*' => Http::response(fixtureBytes('Common/plex/resources.json')),
        'https://203-0-113-2.servermachineidentifier000000000.plex.direct:6022/library/metadata/*' => Http::response(fixtureBytes('Common/plex/library_metadata.json')),
        'https://203-0-113-2.servermachineidentifier000000000.plex.direct:6022/library/all*' => Http::response(fixtureBytes('Common/plex/library_all.json')),
    ]);

    // Act
    $result = resolve(PlexApiService::class)->searchByExternalId('token', 'imdb://tt1375666', 1);

    // Assert
    expect($result->count())->toBe(1)
        ->and($result->first()['match']['ratingKey'] ?? null)->toBe('34277')
        ->and($result->first()['match']['summary'] ?? null)->not->toBeNull()
        ->and(array_key_exists('Guid', $result->first()['match'] ?? []))->toBeTrue();
});

it('keeps the healthy server and drops the down one', function (): void {
    // Arrange
    Http::fake([
        'https://metadata.provider.plex.tv/library/metadata/matches*' => Http::response(fixtureBytes('Common/plex/metadata_matches.json')),
        'https://clients.plex.tv/api/v2/resources*' => Http::response(fixtureBytes('Common/plex/resources_two_servers.json')),
        'https://good-9.plex.direct:32400/library/metadata/*' => Http::response(fixtureBytes('Common/plex/library_metadata.json')),
        'https://good-9.plex.direct:32400/library/all*' => Http::response(fixtureBytes('Common/plex/library_all.json')),
        'https://bad-9.plex.direct:32400/library/all*' => Http::response('', 500),
    ]);

    // Act
    $result = resolve(PlexApiService::class)->searchByExternalId('token', 'imdb://tt1375666', 1);

    // Assert
    expect($result->pluck('clientIdentifier')->all())->toBe(['good-cid']);
});

it('fails a down pooled server fast without retrying', function (): void {
    // Arrange
    Http::fake([
        'https://metadata.provider.plex.tv/library/metadata/matches*' => Http::response(fixtureBytes('Common/plex/metadata_matches.json')),
        'https://clients.plex.tv/api/v2/resources*' => Http::response(fixtureBytes('Common/plex/resources_two_servers.json')),
        'https://good-9.plex.direct:32400/library/metadata/*' => Http::response(fixtureBytes('Common/plex/library_metadata.json')),
        'https://good-9.plex.direct:32400/library/all*' => Http::response(fixtureBytes('Common/plex/library_all.json')),
        'https://bad-9.plex.direct:32400/library/all*' => Http::response('', 500),
    ]);

    // Act
    resolve(PlexApiService::class)->searchByExternalId('token', 'imdb://tt1375666', 1);

    // Assert
    expect(
        Http::recorded(fn ($r): bool => str_starts_with((string) $r->url(), 'https://bad-9.plex.direct:32400/library/all'))->count()
    )->toBe(1);
});
