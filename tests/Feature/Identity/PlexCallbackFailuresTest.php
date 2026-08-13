<?php

declare(strict_types=1);

use App\Domains\Identity\Models\User;
use Illuminate\Support\Facades\Http;

/*
|--------------------------------------------------------------------------
| Plex callback, failure paths — FLIX-241 slice 6
|--------------------------------------------------------------------------
| Every way GET /auth/plex/callback can refuse a guest lands them back on
| /login with its own message in errors.plex, and never stashes a
| plex_registration for the registration form to trust. The success path lives
| in PlexCallbackTest.
|
| The refusals:
|   no stashed PIN (a bare callback hit)   → plex.auth_failed
|   the PIN was never claimed at plex.tv   → plex.auth_failed
|   a Plex call fails (5xx, or a 401 from
|     a token revoked mid-flow)            → plex.auth_failed
|   the account comes back with no id      → plex.auth_failed
|   the account cannot reach our server    → plex.no_access
|   the Plex account is already registered → plex.already_linked
|
| The Plex-call failures are the one arrangement real fixtures cannot produce, so
| the failing statuses are synthesized: a 5xx on each of the three calls the
| callback makes, plus a 401 on the user fetch (the token revoked between
| claiming the PIN and reading the account). The id-less account body is
| synthetic for the same reason — a real Plex account always carries an id — and
| it is arranged alongside an existing password-registered user (every _plex_*
| column null) because users._plex_id is a nullable integer column: an unguarded
| lookup on a null id compiles to IS NULL and would match that row.
|
| Fixtures (byte-exact real captures, tokens redacted):
|   tests/Fixtures/Common/plex/pin_unclaimed.json — PIN 538114995 still
|     pending, authToken null
|   tests/Fixtures/Common/plex/pin_claimed.json — the same PIN once claimed,
|     authToken REDACTED-authToken
|   tests/Fixtures/Common/plex/user.json — the account behind that token
|     (id 1001, username plexuser1, email user1@example.com)
|   tests/Fixtures/Common/plex/resources.json — 3 server resources; resource 0
|     clientIdentifier servermachineidentifier000000000, provides server
|
| The no-access case is arranged by pointing services.plex.server_identifier at
| a machine id that appears in NO resource in that real capture, so the access
| check fails against real data rather than a doctored fixture. The copy is
| asserted as the resolved English string, not the lang key, because the string
| is what the guest actually reads.
*/

it('bounces a bare callback hit back to the login page', function (): void {
    // Arrange
    // a guest arriving with no stashed PIN — nothing to set up

    // Act
    $response = $this->get(route('auth.plex.callback'));

    // Assert
    $response->assertRedirect(route('login'));
    $response->assertSessionHasErrors([
        'plex' => 'We could not authenticate your Plex account. Please try again.',
    ]);
    $response->assertSessionMissing('plex_registration');
});

it('bounces the guest back to the login page when the PIN was never claimed', function (): void {
    // Arrange
    Http::fake([
        '*clients.plex.tv/api/v2/pins/*' => Http::response(fixtureBytes('Common/plex/pin_unclaimed.json')),
    ]);
    $this->withSession(['plex_pin_id' => 538114995]);

    // Act
    $response = $this->get(route('auth.plex.callback'));

    // Assert
    $response->assertRedirect(route('login'));
    $response->assertSessionHasErrors([
        'plex' => 'We could not authenticate your Plex account. Please try again.',
    ]);
    $response->assertSessionMissing('plex_registration');
});

it('bounces the guest back to the login page when the PIN exchange fails at Plex', function (): void {
    // Arrange
    Http::fake([
        '*clients.plex.tv/api/v2/pins/*' => Http::response('', 500),
    ]);
    $this->withSession(['plex_pin_id' => 538114995]);

    // Act
    $response = $this->get(route('auth.plex.callback'));

    // Assert
    $response->assertRedirect(route('login'));
    $response->assertSessionHasErrors([
        'plex' => 'We could not authenticate your Plex account. Please try again.',
    ]);
    $response->assertSessionMissing('plex_registration');
});

it('bounces the guest back to the login page when the Plex user fetch fails', function (): void {
    // Arrange
    Http::fake([
        '*clients.plex.tv/api/v2/pins/*' => Http::response(fixtureBytes('Common/plex/pin_claimed.json')),
        '*plex.tv/api/v2/user*' => Http::response('', 500),
    ]);
    $this->withSession(['plex_pin_id' => 538114995]);

    // Act
    $response = $this->get(route('auth.plex.callback'));

    // Assert
    $response->assertRedirect(route('login'));
    $response->assertSessionHasErrors([
        'plex' => 'We could not authenticate your Plex account. Please try again.',
    ]);
    $response->assertSessionMissing('plex_registration');
});

it('bounces the guest back to the login page when the claimed token is already revoked', function (): void {
    // Arrange
    Http::fake([
        '*clients.plex.tv/api/v2/pins/*' => Http::response(fixtureBytes('Common/plex/pin_claimed.json')),
        '*plex.tv/api/v2/user*' => Http::response('', 401),
    ]);
    $this->withSession(['plex_pin_id' => 538114995]);

    // Act
    $response = $this->get(route('auth.plex.callback'));

    // Assert
    $response->assertRedirect(route('login'));
    $response->assertSessionHasErrors([
        'plex' => 'We could not authenticate your Plex account. Please try again.',
    ]);
    $response->assertSessionMissing('plex_registration');
});

it('bounces the guest back to the login page when the server access check fails', function (): void {
    // Arrange
    config(['services.plex.server_identifier' => 'servermachineidentifier000000000']);
    Http::fake([
        '*clients.plex.tv/api/v2/pins/*' => Http::response(fixtureBytes('Common/plex/pin_claimed.json')),
        '*plex.tv/api/v2/user*' => Http::response(fixtureBytes('Common/plex/user.json')),
        '*clients.plex.tv/api/v2/resources*' => Http::response('', 500),
    ]);
    $this->withSession(['plex_pin_id' => 538114995]);

    // Act
    $response = $this->get(route('auth.plex.callback'));

    // Assert
    $response->assertRedirect(route('login'));
    $response->assertSessionHasErrors([
        'plex' => 'We could not authenticate your Plex account. Please try again.',
    ]);
    $response->assertSessionMissing('plex_registration');
});

it('bounces the guest back to the login page when the Plex account has no id', function (): void {
    // Arrange
    config(['services.plex.server_identifier' => 'servermachineidentifier000000000']);
    Http::fake([
        '*clients.plex.tv/api/v2/pins/*' => Http::response(fixtureBytes('Common/plex/pin_claimed.json')),
        '*plex.tv/api/v2/user*' => Http::response(['uuid' => '0000000000000001', 'username' => 'plexuser1', 'email' => 'user1@example.com']),
        '*clients.plex.tv/api/v2/resources*' => Http::response(fixtureBytes('Common/plex/resources.json')),
    ]);
    User::factory()->create();
    $this->withSession(['plex_pin_id' => 538114995]);

    // Act
    $response = $this->get(route('auth.plex.callback'));

    // Assert
    $response->assertRedirect(route('login'));
    $response->assertSessionHasErrors([
        'plex' => 'We could not authenticate your Plex account. Please try again.',
    ]);
    $response->assertSessionMissing('plex_registration');
    $this->assertDatabaseCount('users', 1);
});

it('turns away a Plex account that cannot reach our server', function (): void {
    // Arrange
    config(['services.plex.server_identifier' => 'someoneelsesmachineidentifier00']);
    Http::fake([
        '*clients.plex.tv/api/v2/pins/*' => Http::response(fixtureBytes('Common/plex/pin_claimed.json')),
        '*plex.tv/api/v2/user*' => Http::response(fixtureBytes('Common/plex/user.json')),
        '*clients.plex.tv/api/v2/resources*' => Http::response(fixtureBytes('Common/plex/resources.json')),
    ]);
    $this->withSession(['plex_pin_id' => 538114995]);

    // Act
    $response = $this->get(route('auth.plex.callback'));

    // Assert
    $response->assertRedirect(route('login'));
    $response->assertSessionHasErrors([
        'plex' => 'Your Plex account does not have access to lundflix.',
    ]);
    $response->assertSessionMissing('plex_registration');
});

it('turns away a Plex account that is already registered', function (): void {
    // Arrange
    config(['services.plex.server_identifier' => 'servermachineidentifier000000000']);
    Http::fake([
        '*clients.plex.tv/api/v2/pins/*' => Http::response(fixtureBytes('Common/plex/pin_claimed.json')),
        '*plex.tv/api/v2/user*' => Http::response(fixtureBytes('Common/plex/user.json')),
        '*clients.plex.tv/api/v2/resources*' => Http::response(fixtureBytes('Common/plex/resources.json')),
    ]);
    User::factory()->create(['_plex_id' => 1001, '_plex_username' => 'plexuser1']);
    $this->withSession(['plex_pin_id' => 538114995]);

    // Act
    $response = $this->get(route('auth.plex.callback'));

    // Assert
    $response->assertRedirect(route('login'));
    $response->assertSessionHasErrors([
        'plex' => 'An account is already registered to this Plex account.',
    ]);
    $response->assertSessionMissing('plex_registration');
    $this->assertGuest();
});
