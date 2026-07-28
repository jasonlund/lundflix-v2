<?php

declare(strict_types=1);

namespace App\Domains\Identity\Http\Controllers;

use App\Domains\Common\Exceptions\PlexRequestFailed;
use App\Domains\Common\Services\PlexApiService;
use App\Domains\Identity\Support\PlexSession;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

final readonly class PlexAuthorizationController
{
    public function __construct(private PlexApiService $plex) {}

    public function __invoke(): Response
    {
        try {
            $pin = $this->plex->createPin();
        } catch (PlexRequestFailed $e) {
            report($e);

            return to_route('login')->withErrors(['plex' => __('plex.pin_creation_failed')]);
        }

        PlexSession::rememberPin($pin['id']);

        // The Plex auth url carries a `#` fragment, which Inertia's middleware turns
        // into a 409 X-Inertia-Redirect — the client then XHRs app.plex.tv instead of
        // navigating. X-Inertia-Location forces a real browser visit.
        return Inertia::location($this->plex->getAuthUrl($pin['code'], route('auth.plex.callback')));
    }
}
