<?php

declare(strict_types=1);

use App\Domains\Common\Data\PlexAccount;
use App\Domains\Identity\Data\VerifiedPlexIdentity;
use App\Domains\Identity\Models\User;
use App\Domains\Identity\Support\PlexSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Inertia\Testing\AssertableInertia as Assert;

/*
|--------------------------------------------------------------------------
| /register — the Plex-gated registration form and its submission
|--------------------------------------------------------------------------
| The form is reachable only with a plex_registration session stashed by the
| Plex callback; submitting it creates the user from that verified identity
| and logs them in. No Plex API call happens on this route, so nothing here
| is faked.
|
| One test reads that stash back through the session serializer rather than
| through the route: /register sees an identity at all only if the JSON round
| trip the handler performs can be reversed, so that seam is asserted here,
| alongside the rest of this form's session contract.
|
| $verifiedPlexIdentity below mirrors tests/Fixtures/Common/plex/user.json
| (account 1001 / plexuser1) — the real capture the callback stashes — with
| the PIN-exchange token appended. It reaches the session through
| PlexSession::stashVerifiedIdentity(), never as a hand-written array: the
| route tests then read the exact payload the callback writes, so a change to
| the stash shape breaks them instead of silently bouncing to /login.
*/

uses(RefreshDatabase::class);

/** @var Closure(): VerifiedPlexIdentity */
$verifiedPlexIdentity = fn (): VerifiedPlexIdentity => new VerifiedPlexIdentity(
    new PlexAccount(
        id: 1001,
        uuid: '0000000000000001',
        username: 'plexuser1',
        email: 'user1@example.com',
        thumb: 'https://plex.tv/users/aaaaaaaaaaaaaaaa/avatar?c=1',
    ),
    token: 'sxWpYzQ1TkxAbCdEfGhI',
);

describe('GET /register form access', function () use ($verifiedPlexIdentity): void {
    it('redirects a guest with no verified plex session away from the registration form', function (): void {
        // Arrange
        // no plex_registration stashed in the session

        // Act
        $response = $this->get('/register');

        // Assert
        $response->assertRedirect('/login');
    });

    // A stash the session cannot turn back into a verified identity — truncated by a
    // deploy, or half-written — must degrade to the no-identity path rather than
    // fatal on the keys it is missing.
    it('redirects a guest whose stashed identity is incomplete away from the registration form', function (): void {
        // Arrange
        $incompleteStash = ['id' => 1001];

        // Act
        $response = $this->withSession(['plex_registration' => $incompleteStash])->get('/register');

        // Assert
        $response->assertRedirect('/login');
    });

    // The session serializes to JSON (config session.serialization), so a stashed
    // PHP object comes back from the handler as a plain array on the next request —
    // the identity has to survive that round trip or /register sees no identity at
    // all. The test driver never writes through a handler, so the encode/decode the
    // real one performs is applied to the stashed value by hand.
    it('reads back a stashed verified identity that has been through the session serializer', function () use ($verifiedPlexIdentity): void {
        // Arrange
        $identity = $verifiedPlexIdentity();
        PlexSession::stashVerifiedIdentity($identity);
        session(['plex_registration' => json_decode((string) json_encode(session('plex_registration')), true)]);

        // Act
        $hydrated = PlexSession::verifiedIdentity();

        // Assert
        expect($hydrated)->toBeInstanceOf(VerifiedPlexIdentity::class)
            ->and($hydrated->account->id)->toBe(1001)
            ->and($hydrated->account->uuid)->toBe('0000000000000001')
            ->and($hydrated->account->username)->toBe('plexuser1')
            ->and($hydrated->account->email)->toBe('user1@example.com')
            ->and($hydrated->account->thumb)->toBe('https://plex.tv/users/aaaaaaaaaaaaaaaa/avatar?c=1')
            ->and($hydrated->token)->toBe('sxWpYzQ1TkxAbCdEfGhI');
    });

    it('renders the registration form with the verified plex username and email', function () use ($verifiedPlexIdentity): void {
        // Arrange
        PlexSession::stashVerifiedIdentity($verifiedPlexIdentity());

        // Act
        $response = $this->get('/register');

        // Assert
        $response->assertInertia(fn (Assert $page): Assert => $page
            ->component('identity/Register')
            ->where('plexUsername', 'plexuser1')
            ->where('plexEmail', 'user1@example.com')
        );
    });
});

describe('POST /register account creation', function () use ($verifiedPlexIdentity): void {
    it('creates the plex user, logs them in, and sends them home', function () use ($verifiedPlexIdentity): void {
        // Arrange
        PlexSession::stashVerifiedIdentity($verifiedPlexIdentity());

        // Act
        $response = $this->post('/register', [
            'name' => 'Jason',
            'password' => 'correct-horse-battery-staple',
            'password_confirmation' => 'correct-horse-battery-staple',
        ]);

        // Assert
        $response->assertRedirect(route('home'));
        $this->assertDatabaseHas('users', [
            'name' => 'Jason',
            'email' => 'user1@example.com',
            '_plex_id' => '1001',
            '_plex_uuid' => '0000000000000001',
            '_plex_username' => 'plexuser1',
            '_plex_thumb' => 'https://plex.tv/users/aaaaaaaaaaaaaaaa/avatar?c=1',
        ]);
        $this->assertAuthenticatedAs(User::query()->where('email', 'user1@example.com')->sole());
    });

    it('logs the new user in for the session only, issuing no remember-me cookie', function () use ($verifiedPlexIdentity): void {
        // Arrange
        PlexSession::stashVerifiedIdentity($verifiedPlexIdentity());

        // Act
        $response = $this->post('/register', [
            'name' => 'Jason',
            'password' => 'correct-horse-battery-staple',
            'password_confirmation' => 'correct-horse-battery-staple',
        ]);

        // Assert
        $response->assertCookieMissing(Auth::guard()->getRecallerName());
        $this->assertAuthenticated();
    });

    it('clears the stashed plex identity once the account is created', function () use ($verifiedPlexIdentity): void {
        // Arrange
        PlexSession::stashVerifiedIdentity($verifiedPlexIdentity());

        // Act
        $response = $this->post('/register', [
            'name' => 'Jason',
            'password' => 'correct-horse-battery-staple',
            'password_confirmation' => 'correct-horse-battery-staple',
        ]);

        // Assert
        $response->assertSessionMissing('plex_registration');
    });
});

describe('POST /register refusals', function () use ($verifiedPlexIdentity): void {
    it('rejects a submission whose password is not confirmed', function () use ($verifiedPlexIdentity): void {
        // Arrange
        PlexSession::stashVerifiedIdentity($verifiedPlexIdentity());

        // Act
        $response = $this->post('/register', [
            'name' => 'Jason',
            'password' => 'correct-horse-battery-staple',
            'password_confirmation' => 'mismatched-confirmation',
        ]);

        // Assert
        $response->assertSessionHasErrors('password');
        expect(User::query()->count())->toBe(0);
        $this->assertGuest();
    });

    it('rejects a submission with the name field omitted entirely', function () use ($verifiedPlexIdentity): void {
        // Arrange
        PlexSession::stashVerifiedIdentity($verifiedPlexIdentity());

        // Act
        $response = $this->post('/register', [
            'password' => 'correct-horse-battery-staple',
            'password_confirmation' => 'correct-horse-battery-staple',
        ]);

        // Assert
        $response->assertSessionHasErrors('name');
        expect(User::query()->count())->toBe(0);
        $this->assertGuest();
    });

    // A field posted as an array (`name[]=a&name[]=b`) is an ordinary PHP request
    // shape, so it must reach the Validator as a missing value and fail there —
    // never the constructor of a ?string property, which would be a 500.
    it('rejects a submission whose name field is posted as an array', function () use ($verifiedPlexIdentity): void {
        // Arrange
        PlexSession::stashVerifiedIdentity($verifiedPlexIdentity());

        // Act
        $response = $this->post('/register', [
            'name' => ['Jason', 'Imposter'],
            'password' => 'correct-horse-battery-staple',
            'password_confirmation' => 'correct-horse-battery-staple',
        ]);

        // Assert
        $response->assertSessionHasErrors('name');
        expect(User::query()->count())->toBe(0);
        $this->assertGuest();
    });

    it('refuses a submission with no verified plex session', function (): void {
        // Arrange
        // no plex_registration stashed in the session

        // Act
        $response = $this->post('/register', [
            'name' => 'Jason',
            'password' => 'correct-horse-battery-staple',
            'password_confirmation' => 'correct-horse-battery-staple',
        ]);

        // Assert
        $response->assertRedirect('/login');
        expect(User::query()->count())->toBe(0);
        $this->assertGuest();
    });
});
