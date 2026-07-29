<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;

/*
|--------------------------------------------------------------------------
| Plex callback, happy path — FLIX-241 slice 5
|--------------------------------------------------------------------------
| GET /auth/plex/callback trades the stashed PIN for its authToken, reads the
| Plex identity behind that token, confirms the account can reach our server,
| stashes the verified identity for the registration form, and forwards the
| guest to /register. Only the success path lives here — every failure branch
| (unclaimed PIN, no stashed PIN, no server access, already registered) is its
| own slice.
|
| Fixtures (byte-exact real captures, tokens redacted):
|   tests/Fixtures/Common/plex/pin_claimed.json — PIN 538114995 already
|     claimed, authToken REDACTED-authToken
|   tests/Fixtures/Common/plex/user.json — the account behind that token
|     (id 1001, uuid 0000000000000001, username plexuser1,
|     email user1@example.com)
|   tests/Fixtures/Common/plex/resources.json — 3 server resources; resource 0
|     clientIdentifier servermachineidentifier000000000, provides server
|
| /register is asserted as a literal path, not route('register'): the redirect
| the guest's browser follows is the contract here, so a rename of the route
| name must not quietly repoint this assertion.
*/

it('forwards the guest to the registration form when the claimed PIN belongs to a user with server access', function (): void {
    // Arrange
    config(['services.plex.server_identifier' => 'servermachineidentifier000000000']);
    Http::fake([
        '*clients.plex.tv/api/v2/pins/*' => Http::response(fixtureBytes('Common/plex/pin_claimed.json')),
        '*plex.tv/api/v2/user*' => Http::response(fixtureBytes('Common/plex/user.json')),
        '*clients.plex.tv/api/v2/resources*' => Http::response(fixtureBytes('Common/plex/resources.json')),
    ]);
    $this->withSession(['plex_pin_id' => 538114995]);

    // Act
    $response = $this->get(route('auth.plex.callback'));

    // Assert
    $response->assertRedirect('/register');
});

it('stashes the verified Plex identity for the registration form', function (): void {
    // Arrange
    config(['services.plex.server_identifier' => 'servermachineidentifier000000000']);
    Http::fake([
        '*clients.plex.tv/api/v2/pins/*' => Http::response(fixtureBytes('Common/plex/pin_claimed.json')),
        '*plex.tv/api/v2/user*' => Http::response(fixtureBytes('Common/plex/user.json')),
        '*clients.plex.tv/api/v2/resources*' => Http::response(fixtureBytes('Common/plex/resources.json')),
    ]);
    $this->withSession(['plex_pin_id' => 538114995]);

    // Act
    $response = $this->get(route('auth.plex.callback'));

    // Assert
    $response->assertSessionHas('plex_registration', [
        'id' => 1001,
        'uuid' => '0000000000000001',
        'username' => 'plexuser1',
        'email' => 'user1@example.com',
        'thumb' => 'https://plex.tv/users/aaaaaaaaaaaaaaaa/avatar?c=1',
        'token' => 'REDACTED-authToken',
    ]);
});

it('clears the consumed PIN id from the session', function (): void {
    // Arrange
    config(['services.plex.server_identifier' => 'servermachineidentifier000000000']);
    Http::fake([
        '*clients.plex.tv/api/v2/pins/*' => Http::response(fixtureBytes('Common/plex/pin_claimed.json')),
        '*plex.tv/api/v2/user*' => Http::response(fixtureBytes('Common/plex/user.json')),
        '*clients.plex.tv/api/v2/resources*' => Http::response(fixtureBytes('Common/plex/resources.json')),
    ]);
    $this->withSession(['plex_pin_id' => 538114995]);

    // Act
    $response = $this->get(route('auth.plex.callback'));

    // Assert
    $response->assertSessionMissing('plex_pin_id');
});
