<?php

declare(strict_types=1);

namespace App\Http\Controllers\Identity;

use App\Domains\Identity\Actions\RegisterPlexUser;
use App\Domains\Identity\Data\PlexRegistrationInput;
use App\Domains\Identity\Data\VerifiedPlexIdentity;
use App\Domains\Identity\Support\PlexSession;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The form is reachable only with the verified identity the Plex callback
 * stashed — it supplies the email and the Plex columns the guest never submits.
 */
final readonly class RegisterController
{
    public function __construct(private RegisterPlexUser $registerPlexUser) {}

    public function create(): Response|RedirectResponse
    {
        $plex = PlexSession::verifiedIdentity();

        if (! $plex instanceof VerifiedPlexIdentity) {
            return to_route('login');
        }

        return Inertia::render('identity/Register', [
            'plexUsername' => $plex->account->username,
            'plexEmail' => $plex->account->email,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $plex = PlexSession::verifiedIdentity();

        if (! $plex instanceof VerifiedPlexIdentity) {
            return to_route('login');
        }

        // The stash is consumed only once the account exists: a ValidationException
        // bubbles out of here with the identity intact, so the redirect back to the
        // form still has something to render.
        $user = $this->registerPlexUser->handle($plex, new PlexRegistrationInput(
            name: $this->submittedString($request, 'name'),
            password: $this->submittedString($request, 'password'),
            passwordConfirmation: $this->submittedString($request, 'password_confirmation'),
        ));

        PlexSession::forgetVerifiedIdentity();

        Auth::login($user);

        return to_route('home');
    }

    /**
     * A field that was never submitted, or that arrived as something other than a
     * string (`name[]=a&name[]=b` is an ordinary POST shape), reads as null — the
     * state PlexRegistrationInput's nullable properties exist to carry, so the
     * Validator inside RegisterPlexUser refuses it instead of the constructor
     * fataling on it. `$request->string()` cannot express that: it stringifies a
     * missing field to '' and raises on an array.
     */
    private function submittedString(Request $request, string $field): ?string
    {
        $value = $request->input($field);

        return is_string($value) ? $value : null;
    }
}
