<?php

declare(strict_types=1);

use App\Domains\Common\Services\PlexApiService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;

/*
|--------------------------------------------------------------------------
| Plex API service — resources + server access slice
|--------------------------------------------------------------------------
| Mirrors tests/Feature/Common/PlexUserTest.php (host-pattern Http::fake,
| resolve() the service, Http::assertSent). Covers the resource list keyed by
| the caller-passed X-Plex-Token and the server-access predicate over it:
|
|   getUserResources($token) — GET clients.plex.tv/api/v2/resources with the
|     includeHttps / includeRelay / includeIPv6 query flags, returns a Collection
|     of the 3 server resources.
|   hasServerAccess($token)  — true iff a returned resource's clientIdentifier
|     matches services.plex.server_identifier, else false.
|
| Fixture (byte-exact real capture):
|   tests/Fixtures/Common/plex/resources.json — top-level array of 3 server
|     resources; resource 0 clientIdentifier servermachineidentifier000000000
|     (owned, present), 1/2 clientslappy / clientsofia3.
*/

it('returns a Collection of the 3 resources from GET clients.plex.tv/api/v2/resources with the include flags', function (): void {
    Http::fake([
        '*clients.plex.tv/api/v2/resources*' => Http::response(fixtureBytes('Common/plex/resources.json')),
    ]);

    $resources = resolve(PlexApiService::class)->getUserResources('the-token');

    expect($resources)->toBeInstanceOf(Collection::class)
        ->and($resources->count())->toBe(3);
    Http::assertSent(fn ($request): bool => str_contains((string) $request->url(), 'clients.plex.tv/api/v2/resources')
        && (string) data_get($request->data(), 'includeHttps') !== ''
        && (string) data_get($request->data(), 'includeRelay') !== ''
        && (string) data_get($request->data(), 'includeIPv6') !== '');
});

it('returns true from hasServerAccess when a resource matches the configured server id', function (): void {
    config(['services.plex.server_identifier' => 'servermachineidentifier000000000']);
    Http::fake([
        '*clients.plex.tv/api/v2/resources*' => Http::response(fixtureBytes('Common/plex/resources.json')),
    ]);

    $hasAccess = resolve(PlexApiService::class)->hasServerAccess('the-token');

    expect($hasAccess)->toBeTrue();
});

it('returns false from hasServerAccess when no resource matches the configured server id', function (): void {
    config(['services.plex.server_identifier' => 'no-such-server']);
    Http::fake([
        '*clients.plex.tv/api/v2/resources*' => Http::response(fixtureBytes('Common/plex/resources.json')),
    ]);

    $hasAccess = resolve(PlexApiService::class)->hasServerAccess('the-token');

    expect($hasAccess)->toBeFalse();
});
