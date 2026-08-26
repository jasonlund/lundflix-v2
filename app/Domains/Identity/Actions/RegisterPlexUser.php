<?php

declare(strict_types=1);

namespace App\Domains\Identity\Actions;

use App\Domains\Identity\Data\PlexRegistrationInput;
use App\Domains\Identity\Data\VerifiedPlexIdentity;
use App\Domains\Identity\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class RegisterPlexUser
{
    use PasswordValidationRules;

    /**
     * $plex is the server-verified identity — trusted, unvalidated; $input is the
     * form's half — untrusted, validated.
     *
     * @throws ValidationException
     */
    public function handle(VerifiedPlexIdentity $plex, PlexRegistrationInput $input): User
    {
        // The email is never taken from $input — a submitted one is spoofable, and
        // PlexRegistrationInput carries no email field at all — so it is folded in
        // from the verified account to be uniqueness-checked.
        Validator::make([
            'name' => $input->name,
            'email' => $plex->account->email,
            'password' => $input->password,
            'password_confirmation' => $input->passwordConfirmation,
        ], [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique(User::class),
            ],
            'password' => $this->passwordRules(),
        ])->validate();

        return User::create([
            'name' => $input->name,
            'email' => $plex->account->email,
            'password' => Hash::make($input->password),
            '_plex_id' => $plex->account->id,
            '_plex_uuid' => $plex->account->uuid,
            '_plex_username' => $plex->account->username,
            '_plex_thumb' => $plex->account->thumb,
            '_plex_token' => $plex->token,
        ]);
    }
}
