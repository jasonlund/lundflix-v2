<?php

declare(strict_types=1);

use App\Domains\Common\Services\PlexApiService;
use Illuminate\Support\Facades\Http;

/*
|--------------------------------------------------------------------------
| Plex PIN auth + request-header foundation — FLIX-130 slice 1
|--------------------------------------------------------------------------
| The PIN auth flow: createPin() POSTs to clients.plex.tv to mint a linking
| PIN, carrying the X-Plex-* identity headers; getTokenFromPin() polls that
| PIN and returns the authToken once a user claims it (null while unclaimed);
| getAuthUrl() builds the app.plex.tv hand-off URL the user opens to claim it.
|
| Fixtures (byte-exact real captures, tokens redacted):
|   tests/Fixtures/Common/plex/pin_create.json — fresh PIN (id 538114995,
|     code m6mijjn177ut0qaz02b9iedof, authToken null)
|   tests/Fixtures/Common/plex/pin_claimed.json — same PIN, authToken set
|   tests/Fixtures/Common/plex/pin_unclaimed.json — same PIN, authToken null
*/

it('returns the id and code from POST /api/v2/pins', function (): void {
    Http::fake([
        '*clients.plex.tv/api/v2/pins*' => Http::response(fixtureBytes('Common/plex/pin_create.json')),
    ]);

    $pin = resolve(PlexApiService::class)->createPin();

    expect($pin)->toBe(['id' => 538114995, 'code' => 'm6mijjn177ut0qaz02b9iedof']);
});

it('sends the X-Plex identity headers on the createPin request', function (): void {
    config(['services.plex.client_identifier' => 'lundflix']);
    Http::fake([
        '*clients.plex.tv/api/v2/pins*' => Http::response(fixtureBytes('Common/plex/pin_create.json')),
    ]);

    resolve(PlexApiService::class)->createPin();

    Http::assertSent(fn ($request): bool => str_contains((string) $request->url(), 'clients.plex.tv/api/v2/pins')
        && $request->hasHeader('X-Plex-Client-Identifier', config('services.plex.client_identifier'))
        && $request->hasHeader('X-Plex-Product', 'lundflix'));
});

it('returns the authToken when the PIN is claimed', function (): void {
    Http::fake([
        '*clients.plex.tv/api/v2/pins/*' => Http::response(fixtureBytes('Common/plex/pin_claimed.json')),
    ]);

    $token = resolve(PlexApiService::class)->getTokenFromPin(538114995);

    expect($token)->toBe('REDACTED-authToken');
});

it('returns null when the PIN is still unclaimed', function (): void {
    Http::fake([
        '*clients.plex.tv/api/v2/pins/*' => Http::response(fixtureBytes('Common/plex/pin_unclaimed.json')),
    ]);

    $token = resolve(PlexApiService::class)->getTokenFromPin(538114995);

    expect($token)->toBeNull();
    Http::assertSent(fn ($request): bool => str_contains((string) $request->url(), 'clients.plex.tv/api/v2/pins/538114995'));
});

it('builds the app.plex.tv auth hand-off URL with the PIN code and forward url', function (): void {
    $forwardUrl = 'https://lundflix.test/callback';

    $url = resolve(PlexApiService::class)->getAuthUrl('m6mijjn177ut0qaz02b9iedof', $forwardUrl);

    expect($url)->toStartWith('https://app.plex.tv/auth#?');
    // The query lives in the URL fragment after '#?'; strip the leading '?' before parsing.
    $query = ltrim((string) parse_url($url, PHP_URL_FRAGMENT), '?');
    parse_str($query, $params);
    // parse_str decodes the bracketed param into a nested array, so the product
    // lands at the dotted path context.device.product (not a literal flat key).
    expect($params)
        ->toHaveKey('clientID')
        ->toHaveKey('code', 'm6mijjn177ut0qaz02b9iedof')
        ->toHaveKey('forwardUrl', $forwardUrl)
        ->toHaveKey('context.device.product', 'lundflix');
});
