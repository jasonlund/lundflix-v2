<?php

declare(strict_types=1);

use App\Domains\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
| $verifiedPlexAccount below mirrors tests/Fixtures/Common/plex/user.json
| (account 1001 / plexuser1) — the real capture the callback stashes — with
| the PIN-exchange token appended.
*/

uses(RefreshDatabase::class);

/** @var Closure(): array{id: int, uuid: string, username: string, email: string, thumb: string, token: string} */
$verifiedPlexAccount = fn (): array => [
    'id' => 1001,
    'uuid' => '0000000000000001',
    'username' => 'plexuser1',
    'email' => 'user1@example.com',
    'thumb' => 'https://plex.tv/users/aaaaaaaaaaaaaaaa/avatar?c=1',
    'token' => 'sxWpYzQ1TkxAbCdEfGhI',
];

it('redirects a guest with no verified plex session away from the registration form', function (): void {
    // Arrange
    // no plex_registration stashed in the session

    // Act
    $response = $this->get('/register');

    // Assert
    $response->assertRedirect('/login');
});

it('renders the registration form with the verified plex username and email', function () use ($verifiedPlexAccount): void {
    // Arrange
    $plex = $verifiedPlexAccount();

    // Act
    $response = $this->withSession(['plex_registration' => $plex])->get('/register');

    // Assert
    $response->assertInertia(fn (Assert $page): Assert => $page
        ->component('auth/Register')
        ->where('plexUsername', 'plexuser1')
        ->where('plexEmail', 'user1@example.com')
    );
});

it('creates the plex user, logs them in, and sends them home', function () use ($verifiedPlexAccount): void {
    // Arrange
    $plex = $verifiedPlexAccount();

    // Act
    $response = $this->withSession(['plex_registration' => $plex])->post('/register', [
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

it('clears the stashed plex identity once the account is created', function () use ($verifiedPlexAccount): void {
    // Arrange
    $plex = $verifiedPlexAccount();

    // Act
    $response = $this->withSession(['plex_registration' => $plex])->post('/register', [
        'name' => 'Jason',
        'password' => 'correct-horse-battery-staple',
        'password_confirmation' => 'correct-horse-battery-staple',
    ]);

    // Assert
    $response->assertSessionMissing('plex_registration');
});

it('rejects a submission whose password is not confirmed', function () use ($verifiedPlexAccount): void {
    // Arrange
    $plex = $verifiedPlexAccount();

    // Act
    $response = $this->withSession(['plex_registration' => $plex])->post('/register', [
        'name' => 'Jason',
        'password' => 'correct-horse-battery-staple',
        'password_confirmation' => 'mismatched-confirmation',
    ]);

    // Assert
    $response->assertSessionHasErrors('password');
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
