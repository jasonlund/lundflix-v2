<?php

declare(strict_types=1);

namespace App\Domains\Identity\Actions;

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
     * @param  array{id: int|null, uuid: string|null, username: string|null, email: string|null, thumb: string|null, token: string}  $plex  PlexApiService::getUserInfo() plus the PIN's token
     * @param  array<string, string>  $input
     *
     * @throws ValidationException
     */
    public function handle(array $plex, array $input): User
    {
        // The email is never taken from $input — a submitted one is spoofable —
        // so it is folded in from the verified account to be uniqueness-checked.
        Validator::make([
            'name' => $input['name'] ?? null,
            'email' => $plex['email'],
            'password' => $input['password'] ?? null,
            'password_confirmation' => $input['password_confirmation'] ?? null,
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
            'name' => $input['name'],
            'email' => $plex['email'],
            'password' => Hash::make($input['password']),
            '_plex_id' => $plex['id'],
            '_plex_uuid' => $plex['uuid'],
            '_plex_username' => $plex['username'],
            '_plex_thumb' => $plex['thumb'],
            '_plex_token' => $plex['token'],
        ]);
    }
}
