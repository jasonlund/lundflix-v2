<?php

declare(strict_types=1);

use App\Domains\Identity\Actions\RegisterPlexUser;
use App\Domains\Identity\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/*
|--------------------------------------------------------------------------
| RegisterPlexUser — verified Plex identity + submitted registration input
|--------------------------------------------------------------------------
| handle($plex, $input) takes the already-verified Plex account (the shape
| PlexApiService::getUserInfo() returns, plus the PIN's token) as trusted and
| unvalidated, and the form's half ($input: name, password,
| password_confirmation) as untrusted and validated.
|
| $verifiedPlexAccount below mirrors tests/Fixtures/Common/plex/user.json
| (account 1001 / plexuser1), the real capture getUserInfo() is tested against,
| with the PIN-exchange token appended.
*/

/** @var Closure(): array{id: int, uuid: string, username: string, email: string, thumb: string, token: string} */
$verifiedPlexAccount = fn (): array => [
    'id' => 1001,
    'uuid' => '0000000000000001',
    'username' => 'plexuser1',
    'email' => 'user1@example.com',
    'thumb' => 'https://plex.tv/users/aaaaaaaaaaaaaaaa/avatar?c=1',
    'token' => 'sxWpYzQ1TkxAbCdEfGhI',
];

it('creates a user from the verified plex identity and the submitted name and password', function () use ($verifiedPlexAccount): void {
    // Arrange
    $plex = $verifiedPlexAccount();

    // Act
    $user = resolve(RegisterPlexUser::class)->handle($plex, [
        'name' => 'Jason',
        // A submitted email is spoofable — the form renders it read-only, so the
        // created user's email must come from $plex, never from $input.
        'email' => 'attacker@example.com',
        'password' => 'correct-horse-battery-staple',
        'password_confirmation' => 'correct-horse-battery-staple',
    ]);

    // Assert
    $this->assertDatabaseHas('users', [
        'id' => $user->id,
        'name' => 'Jason',
        'email' => 'user1@example.com',
        '_plex_id' => '1001',
        '_plex_uuid' => '0000000000000001',
        '_plex_username' => 'plexuser1',
        '_plex_thumb' => 'https://plex.tv/users/aaaaaaaaaaaaaaaa/avatar?c=1',
    ]);
    $stored = User::query()->findOrFail($user->id);
    expect($stored->password)->not->toBe('correct-horse-battery-staple')
        ->and(Hash::check('correct-horse-battery-staple', $stored->password))->toBeTrue()
        ->and($stored->_plex_token)->toBe('sxWpYzQ1TkxAbCdEfGhI');
});

it('rejects a registration with no name', function () use ($verifiedPlexAccount): void {
    // Arrange
    $plex = $verifiedPlexAccount();

    // Act & Assert
    expect(fn (): User => resolve(RegisterPlexUser::class)->handle($plex, [
        'name' => '',
        'password' => 'correct-horse-battery-staple',
        'password_confirmation' => 'correct-horse-battery-staple',
    ]))->toThrow(ValidationException::class);
    expect(User::query()->count())->toBe(0);
});

it('rejects a registration whose password is too weak and unconfirmed', function () use ($verifiedPlexAccount): void {
    // Arrange
    $plex = $verifiedPlexAccount();

    // Act & Assert
    expect(fn (): User => resolve(RegisterPlexUser::class)->handle($plex, [
        'name' => 'Jason',
        'password' => 'short',
        'password_confirmation' => 'mismatched',
    ]))->toThrow(ValidationException::class);
    expect(User::query()->count())->toBe(0);
});

it('rejects a plex account whose email is already registered', function () use ($verifiedPlexAccount): void {
    // Arrange
    User::factory()->create(['email' => 'user1@example.com']);
    $plex = $verifiedPlexAccount();

    // Act & Assert
    expect(fn (): User => resolve(RegisterPlexUser::class)->handle($plex, [
        'name' => 'Jason',
        'password' => 'correct-horse-battery-staple',
        'password_confirmation' => 'correct-horse-battery-staple',
    ]))->toThrow(ValidationException::class);
    expect(User::query()->count())->toBe(1);
});
