<?php

declare(strict_types=1);

use App\Domains\PlexLibrary\Exceptions\ConfiguredPlexServerUnavailable;
use App\Domains\PlexLibrary\Services\PlexLibraryService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Plex library service — server connection slice
|--------------------------------------------------------------------------
| Mirrors tests/Feature/Common/PlexServerAccessTest.php (host-pattern
| Http::fake, resolve() the service, Http::assertSent). serverConnection()
| resolves the configured online server to its uri+accessToken via the config
| OWNER token, throwing typed when that server isn't online. The container
| injects the real PlexApiService, whose discovery HTTP is faked at the wire.
|
| Fixture (byte-exact real capture):
|   tests/Fixtures/Common/plex/resources.json — top-level array of 3 server
|     resources; resource 0 clientIdentifier servermachineidentifier000000000
|     (owned, present) with best direct-https uri
|     https://203-0-113-2.servermachineidentifier000000000.plex.direct:6022.
*/

it('sends the configured owner token as X-Plex-Token on discovery', function (): void {
    // Arrange
    config([
        'services.plex.token' => 'owner-token-xyz',
        'services.plex.server_identifier' => 'servermachineidentifier000000000',
    ]);
    Http::fake([
        '*clients.plex.tv/api/v2/resources*' => Http::response(fixtureBytes('Common/plex/resources.json')),
    ]);

    // Act
    resolve(PlexLibraryService::class)->serverConnection();

    // Assert
    Http::assertSent(fn ($request): bool => Str::contains((string) $request->url(), 'clients.plex.tv/api/v2/resources')
        && $request->header('X-Plex-Token')[0] === 'owner-token-xyz');
});

it('returns the matching server uri and access token', function (): void {
    // Arrange
    config([
        'services.plex.token' => 'owner-token-xyz',
        'services.plex.server_identifier' => 'servermachineidentifier000000000',
    ]);
    Http::fake([
        '*clients.plex.tv/api/v2/resources*' => Http::response(fixtureBytes('Common/plex/resources.json')),
    ]);

    // Act
    $connection = resolve(PlexLibraryService::class)->serverConnection();

    // Assert
    expect($connection['uri'])->toBe('https://203-0-113-2.servermachineidentifier000000000.plex.direct:6022')
        ->and($connection['accessToken'])->not->toBeEmpty();
});

it('throws when no online server matches the configured id', function (): void {
    // Arrange
    config([
        'services.plex.token' => 'owner-token-xyz',
        'services.plex.server_identifier' => 'no-such-server',
    ]);
    Http::fake([
        '*clients.plex.tv/api/v2/resources*' => Http::response(fixtureBytes('Common/plex/resources.json')),
    ]);

    // Act & Assert
    expect(fn () => resolve(PlexLibraryService::class)->serverConnection())
        ->toThrow(ConfiguredPlexServerUnavailable::class);
});
