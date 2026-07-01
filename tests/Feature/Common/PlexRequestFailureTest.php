<?php

declare(strict_types=1);

use App\Domains\Common\Exceptions\PlexAuthenticationFailed;
use App\Domains\Common\Exceptions\PlexRequestFailed;
use App\Domains\Common\Services\PlexApiService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/*
|--------------------------------------------------------------------------
| Plex API service — request-failure mapping (Slice 3)
|--------------------------------------------------------------------------
| Transport and auth failures from plex.tv are normalized into typed domain
| exceptions, never surfaced raw: a transport-level ConnectionException past
| retries and a 401 both map to named exceptions. A 404 on a pin lookup is a
| definitive miss (the token simply isn't claimed yet) and returns null.
*/

it('throws PlexRequestFailed when getUserInfo fails at the transport level', function (): void {
    Http::fake([
        '*plex.tv/api/v2/user*' => fn () => throw new ConnectionException('Connection timed out'),
    ]);

    expect(fn () => resolve(PlexApiService::class)->getUserInfo('token'))->toThrow(PlexRequestFailed::class);
});

it('throws PlexAuthenticationFailed when getUserInfo gets a 401', function (): void {
    Http::fake([
        '*plex.tv/api/v2/user*' => Http::response('', 401),
    ]);

    expect(fn () => resolve(PlexApiService::class)->getUserInfo('token'))->toThrow(PlexAuthenticationFailed::class);
});

it('returns null from getTokenFromPin when the pin 404s', function (): void {
    Http::fake([
        '*clients.plex.tv/api/v2/pins/*' => Http::response('', 404),
    ]);

    expect(resolve(PlexApiService::class)->getTokenFromPin(538114995))->toBeNull();
});
