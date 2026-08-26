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
            name: $request->string('name')->value(),
            password: $request->string('password')->value(),
            passwordConfirmation: $request->string('password_confirmation')->value(),
        ));

        PlexSession::forgetVerifiedIdentity();

        Auth::login($user);

        return to_route('home');
    }
}
