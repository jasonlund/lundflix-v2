<?php

declare(strict_types=1);

use App\Domains\Common\Data\PlexAccount;
use App\Domains\Identity\Actions\RegisterPlexUser;
use App\Domains\Identity\Data\PlexRegistrationInput;
use App\Domains\Identity\Data\VerifiedPlexIdentity;
use App\Domains\Identity\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/*
|--------------------------------------------------------------------------
| RegisterPlexUser — verified Plex identity + submitted registration input
|--------------------------------------------------------------------------
| handle($plex, $input) takes the already-verified Plex account (a PlexAccount,
| plus the PIN's token) as trusted and unvalidated, and the form's half (a
| PlexRegistrationInput: name, password, password confirmation) as untrusted and
| validated. PlexRegistrationInput's properties are nullable because a field the
| guest omitted must reach the validator rather than fail construction.
|
| $verifiedPlexIdentity below mirrors tests/Fixtures/Common/plex/user.json
| (account 1001 / plexuser1), the real capture getUserInfo() is tested against,
| with the PIN-exchange token appended.
*/

/** @var Closure(?string): VerifiedPlexIdentity */
$verifiedPlexIdentity = fn (?string $email = 'user1@example.com'): VerifiedPlexIdentity => new VerifiedPlexIdentity(
    new PlexAccount(
        id: 1001,
        uuid: '0000000000000001',
        username: 'plexuser1',
        email: $email,
        thumb: 'https://plex.tv/users/aaaaaaaaaaaaaaaa/avatar?c=1',
    ),
    token: 'sxWpYzQ1TkxAbCdEfGhI',
);

describe('handle() user creation', function () use ($verifiedPlexIdentity): void {
    it('creates a user from the verified plex identity and the submitted name and password', function () use ($verifiedPlexIdentity): void {
        // Arrange
        $plex = $verifiedPlexIdentity();

        // Act
        $user = resolve(RegisterPlexUser::class)->handle($plex, new PlexRegistrationInput(
            name: 'Jason',
            password: 'correct-horse-battery-staple',
            passwordConfirmation: 'correct-horse-battery-staple',
        ));

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

    // A submitted email is spoofable — the form renders it read-only, so the created
    // user's email must come from the verified identity, never from the input. That
    // guard is now structural: PlexRegistrationInput carries no email field, so a
    // spoofed one has nowhere to travel and the arrangement below cannot even
    // express it.
    it('takes the created user email from the verified identity, which the input cannot override', function () use ($verifiedPlexIdentity): void {
        // Arrange
        $plex = $verifiedPlexIdentity();

        // Act
        $user = resolve(RegisterPlexUser::class)->handle($plex, new PlexRegistrationInput(
            name: 'Jason',
            password: 'correct-horse-battery-staple',
            passwordConfirmation: 'correct-horse-battery-staple',
        ));

        // Assert
        expect($user->email)->toBe('user1@example.com');
    });
});

describe('handle() input validation', function () use ($verifiedPlexIdentity): void {
    it('rejects a registration with no name', function () use ($verifiedPlexIdentity): void {
        // Arrange
        $plex = $verifiedPlexIdentity();

        // Act & Assert
        expect(fn (): User => resolve(RegisterPlexUser::class)->handle($plex, new PlexRegistrationInput(
            name: null,
            password: 'correct-horse-battery-staple',
            passwordConfirmation: 'correct-horse-battery-staple',
        )))->toThrow(ValidationException::class);
        expect(User::query()->count())->toBe(0);
    });

    it('rejects a registration whose password is too weak and unconfirmed', function () use ($verifiedPlexIdentity): void {
        // Arrange
        $plex = $verifiedPlexIdentity();

        // Act & Assert
        expect(fn (): User => resolve(RegisterPlexUser::class)->handle($plex, new PlexRegistrationInput(
            name: 'Jason',
            password: 'short',
            passwordConfirmation: 'mismatched',
        )))->toThrow(ValidationException::class);
        expect(User::query()->count())->toBe(0);
    });
});

describe('handle() plex account validation', function () use ($verifiedPlexIdentity): void {
    // Plex Home managed/restricted profiles can hold server access with no email at
    // all, so getUserInfo() types it string|null — it must fail validation rather
    // than reach the NOT NULL users.email column as an uncaught QueryException.
    it('rejects a plex account with no email', function () use ($verifiedPlexIdentity): void {
        // Arrange
        $plex = $verifiedPlexIdentity(null);

        // Act & Assert
        expect(fn (): User => resolve(RegisterPlexUser::class)->handle($plex, new PlexRegistrationInput(
            name: 'Jason',
            password: 'correct-horse-battery-staple',
            passwordConfirmation: 'correct-horse-battery-staple',
        )))->toThrow(ValidationException::class);
        expect(User::query()->count())->toBe(0);
    });

    it('rejects a plex account whose email is already registered', function () use ($verifiedPlexIdentity): void {
        // Arrange
        User::factory()->create(['email' => 'user1@example.com']);
        $plex = $verifiedPlexIdentity();

        // Act & Assert
        expect(fn (): User => resolve(RegisterPlexUser::class)->handle($plex, new PlexRegistrationInput(
            name: 'Jason',
            password: 'correct-horse-battery-staple',
            passwordConfirmation: 'correct-horse-battery-staple',
        )))->toThrow(ValidationException::class);
        expect(User::query()->count())->toBe(1);
    });
});
