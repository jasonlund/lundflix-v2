<?php

declare(strict_types=1);

namespace App\Http\Controllers\Identity;

use App\Domains\Common\Exceptions\PlexRequestFailed;
use App\Domains\Common\Services\PlexApiService;
use App\Domains\Identity\Exceptions\PlexPinMissingCode;
use App\Domains\Identity\Support\PlexSession;
use Illuminate\Http\RedirectResponse;
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

            return $this->refuse();
        }

        $code = $pin['code'];

        // Plex can answer 2xx without a code; that value is the whole point of the
        // hand-off, so an unusable one is a Plex failure like any transport error —
        // without this guard it reaches getAuthUrl(string) as null and 500s.
        if (! is_string($code) || $code === '') {
            report(PlexPinMissingCode::onStart($pin['id']));

            return $this->refuse();
        }

        PlexSession::rememberPin($pin['id']);

        // The Plex auth url carries a `#` fragment, which Inertia's middleware turns
        // into a 409 X-Inertia-Redirect — the client then XHRs app.plex.tv instead of
        // navigating. X-Inertia-Location forces a real browser visit.
        return Inertia::location($this->plex->getAuthUrl($code, route('auth.plex.callback')));
    }

    private function refuse(): RedirectResponse
    {
        return to_route('login')->withErrors(['plex' => __('plex.pin_creation_failed')]);
    }
}
