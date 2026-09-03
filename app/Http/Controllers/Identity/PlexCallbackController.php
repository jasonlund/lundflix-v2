<?php

declare(strict_types=1);

namespace App\Http\Controllers\Identity;

use App\Domains\Common\Exceptions\PlexAuthenticationFailed;
use App\Domains\Common\Exceptions\PlexRequestFailed;
use App\Domains\Common\Services\PlexApiService;
use App\Domains\Identity\Data\VerifiedPlexIdentity;
use App\Domains\Identity\Exceptions\PlexAccountAlreadyRegistered;
use App\Domains\Identity\Exceptions\PlexAccountLacksServerAccess;
use App\Domains\Identity\Exceptions\PlexAccountMissingId;
use App\Domains\Identity\Exceptions\PlexPinMissingFromSession;
use App\Domains\Identity\Exceptions\PlexPinNotClaimed;
use App\Domains\Identity\Models\User;
use App\Domains\Identity\Support\PlexSession;
use Illuminate\Http\RedirectResponse;

/**
 * Every refusal below is report()ed and never thrown: the guest is bounced to
 * /login with deliberately vague copy, so the Plex-side detail that says which
 * account failed and why only reaches the operator's log.
 */
final readonly class PlexCallbackController
{
    public function __construct(private PlexApiService $plex) {}

    public function __invoke(): RedirectResponse
    {
        $pinId = PlexSession::pullPinId();

        if ($pinId === null) {
            report(PlexPinMissingFromSession::onCallback());

            return $this->refuse('plex.auth_failed');
        }

        // One try spans every Plex call: a transport failure or a token revoked
        // mid-flow is the same story to the guest, and wrapping each call
        // separately would repeat this catch three times.
        try {
            $token = $this->plex->getTokenFromPin($pinId);

            if ($token === null) {
                report(PlexPinNotClaimed::for($pinId));

                return $this->refuse('plex.auth_failed');
            }

            $plexUser = $this->plex->getUserInfo($token);
            $hasServerAccess = $this->plex->hasServerAccess($token);
        } catch (PlexAuthenticationFailed|PlexRequestFailed $e) {
            report($e);

            return $this->refuse('plex.auth_failed');
        }

        // users._plex_id is a nullable integer column, so a null id would turn the
        // lookup below into an IS NULL that matches any password-registered account.
        if ($plexUser->id === null) {
            report(PlexAccountMissingId::for($pinId));

            return $this->refuse('plex.auth_failed');
        }

        if (! $hasServerAccess) {
            report(PlexAccountLacksServerAccess::for($plexUser));

            return $this->refuse('plex.no_access');
        }

        $existing = User::query()->where('_plex_id', $plexUser->id)->first();

        if ($existing !== null) {
            report(PlexAccountAlreadyRegistered::for($plexUser, $existing->getKey()));

            return $this->refuse('plex.already_linked');
        }

        PlexSession::stashVerifiedIdentity(new VerifiedPlexIdentity($plexUser, $token));

        return to_route('register');
    }

    /**
     * The key is passed whole rather than assembled from a suffix so every
     * lang key in this file stays greppable.
     */
    private function refuse(string $messageKey): RedirectResponse
    {
        return to_route('login')->withErrors(['plex' => __($messageKey)]);
    }
}
