<?php

declare(strict_types=1);

use App\Domains\Common\Services\PlexApiService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;

/*
|--------------------------------------------------------------------------
| Plex API service — online servers + best-connection selection slice
|--------------------------------------------------------------------------
| getOnlineServers($token) calls getUserResources under the hood (GET
| clients.plex.tv/api/v2/resources), then filters to present server resources
| that expose at least one usable (non-local) connection, and projects each to
| a slim shape with the best reachable uri.
|
| Fixtures:
|   tests/Fixtures/Common/plex/resources.json — byte-exact real capture of 3
|     server resources:
|       lundflix  (servermachineidentifier000000000) — owned, present; best
|         non-local uri http://server.example.com:6022 (local 10-1-1-2 skipped).
|       slappy    (clientslappy)  — presence false → excluded.
|       sofia3    (clientsofia3)  — present but every connection is local → no
|         usable uri → dropped.
|   tests/Fixtures/Common/plex/resources_connection_mix.json — SYNTHETIC (a
|     connection mix real data can't reliably produce). One present/owned server
|     "mix-cid" whose connections are ordered local→relay→IPv6→IPv4 so a naive
|     "first non-local" can't pass the preference test by accident. Best uri must
|     resolve to the IPv4 connection.
*/

it('filters to present servers and projects each to the slim shape', function (): void {
    // Arrange
    Http::fake([
        '*clients.plex.tv/api/v2/resources*' => Http::response(fixtureBytes('Common/plex/resources.json')),
    ]);

    // Act
    $result = resolve(PlexApiService::class)->getOnlineServers('the-token');

    // Assert
    expect($result)->toBeInstanceOf(Collection::class)
        ->and($result->count())->toBe(1)
        ->and($result->first()['clientIdentifier'])->toBe('servermachineidentifier000000000')
        ->and(array_keys($result->first()))->toBe(['name', 'clientIdentifier', 'accessToken', 'owned', 'uri'])
        ->and($result->pluck('clientIdentifier')->all())->not->toContain('clientslappy');
});

it('prefers IPv4 over IPv6 over relay when choosing the best connection', function (): void {
    // Arrange
    Http::fake([
        '*clients.plex.tv/api/v2/resources*' => Http::response(fixtureBytes('Common/plex/resources_connection_mix.json')),
    ]);

    // Act
    $result = resolve(PlexApiService::class)->getOnlineServers('the-token');

    // Assert
    expect($result->first()['uri'] ?? null)->toBe('https://ipv4-9.mix.plex.direct:32400');
});

it('skips local connections and prefers an https uri over an earlier http one in the same class', function (): void {
    // The lundflix server lists an http:// direct connection BEFORE a non-local
    // https:// direct one; secure transport must win within the class.
    // Arrange
    Http::fake([
        '*clients.plex.tv/api/v2/resources*' => Http::response(fixtureBytes('Common/plex/resources.json')),
    ]);

    // Act
    $result = resolve(PlexApiService::class)->getOnlineServers('the-token');

    // Assert
    expect($result->first()['uri'] ?? null)->toBe('https://203-0-113-2.servermachineidentifier000000000.plex.direct:6022');
});

it('treats an unflagged plex.direct host with a stray hex letter as a direct (IPv4-class) connection', function (): void {
    // The only non-local direct connections carry no explicit IPv6 flag;
    // "deadbox" has hex letters but is not a dash-encoded IPv6 label, so it must
    // be classed direct (IPv4) and, being first, win the direct slot.
    // Arrange
    Http::fake([
        '*clients.plex.tv/api/v2/resources*' => Http::response(fixtureBytes('Common/plex/resources_unflagged_direct.json')),
    ]);

    // Act
    $result = resolve(PlexApiService::class)->getOnlineServers('the-token');

    // Assert
    expect($result->first()['uri'] ?? null)->toBe('https://deadbox.unflagged.plex.direct:32400');
});

it('drops a server with no usable (non-local) connection', function (): void {
    // Arrange
    Http::fake([
        '*clients.plex.tv/api/v2/resources*' => Http::response(fixtureBytes('Common/plex/resources.json')),
    ]);

    // Act
    $result = resolve(PlexApiService::class)->getOnlineServers('the-token');

    // Assert
    $identifiers = $result->pluck('clientIdentifier')->all();
    expect($identifiers)->toContain('servermachineidentifier000000000')
        ->and($identifiers)->not->toContain('clientsofia3');
});
