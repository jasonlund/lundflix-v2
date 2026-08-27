<?php

declare(strict_types=1);

use App\Domains\Common\Data\PlexAccount;
use App\Domains\Identity\Data\VerifiedPlexIdentity;
use App\Domains\Identity\Models\User;
use App\Domains\Identity\Support\PlexSession;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
|--------------------------------------------------------------------------
| /register — the Plex registration form driven in a real browser
|--------------------------------------------------------------------------
| The Feature suite posts to /register over raw HTTP, so React never runs; the
| Vitest suite renders the page with @inertiajs/react stubbed, so the submit
| never leaves the component. Neither one proves the form a guest actually
| fills reaches the controller. These tests close that seam: real Chromium,
| real Inertia <Form>, real round trip.
|
| The hand-off leg is deliberately out of scope — getAuthUrl() points at
| app.plex.tv, and a browser test that followed it would hit a third party on
| every CI run. Coverage of that redirect stays in PlexAuthorizationStartTest.
|
| $verifiedPlexIdentity mirrors tests/Fixtures/Common/plex/user.json (account
| 1001 / plexuser1) — the real capture the callback stashes — with the PIN
| token appended, and is stashed through PlexSession itself because the
| callback has already run by the time the guest sees this form. Going through
| the production marshaller rather than a hand-written session array is what
| keeps a change to the stash shape from silently bouncing these tests to
| /login.
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

describe('/register browser round trip', function () use ($verifiedPlexIdentity): void {
    it('carries a guest from the prefilled form to the signed-in home page', function () use ($verifiedPlexIdentity): void {
        // Arrange
        PlexSession::stashVerifiedIdentity($verifiedPlexIdentity());

        // Act
        $page = visit('/register');

        $page->assertValue('#plex_username', 'plexuser1')
            ->assertValue('#plex_email', 'user1@example.com')
            ->assertValue('#name', 'plexuser1')
            ->fill('#name', 'Plex User One')
            ->fill('#password', 'sT0rmy-petrel-42')
            ->fill('#password_confirmation', 'sT0rmy-petrel-42')
            ->click('Create account');

        // Assert
        $page->assertPathIs('/')
            ->assertSee('lundflix')
            ->assertNoJavaScriptErrors();

        $this->assertAuthenticated();

        $user = User::query()->sole();
        expect($user->name)->toBe('Plex User One')
            ->and($user->email)->toBe('user1@example.com')
            ->and($user->_plex_id)->toBe(1001)
            ->and($user->_plex_username)->toBe('plexuser1');
    });

    it('shows the server validation error and keeps the guest on the form', function () use ($verifiedPlexIdentity): void {
        // Arrange
        PlexSession::stashVerifiedIdentity($verifiedPlexIdentity());

        // Act
        $page = visit('/register');

        $page->fill('#name', 'Plex User One')
            ->fill('#password', 'sT0rmy-petrel-42')
            ->fill('#password_confirmation', 'a-different-password')
            ->click('Create account');

        // Assert
        $page->assertPathIs('/register')
            ->assertSee('The password field confirmation does not match.')
            ->assertNoJavaScriptErrors();

        $this->assertGuest();

        expect(User::query()->count())->toBe(0);
    });
});
