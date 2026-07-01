<?php

declare(strict_types=1);

use App\Domains\Common\Services\PlexApiService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;

/*
|--------------------------------------------------------------------------
| Plex API service — user info + friends slice
|--------------------------------------------------------------------------
| Mirrors tests/Feature/Catalog/TvdbApiServiceTest.php (host-pattern Http::fake,
| resolve() the service, Http::assertSent). Covers two reads keyed by the
| caller-passed X-Plex-Token:
|
|   getUserInfo($token) — GET plex.tv/api/v2/user, returns the trimmed account
|     shape; the request carries X-Plex-Token and never an Authorization header.
|   getFriends($token)  — GET clients.plex.tv/api/v2/friends, returns a Collection
|     of the 3 friends.
|
| Fixtures (byte-exact real captures):
|   tests/Fixtures/Common/plex/user.json    — account 1001 / plexuser1
|   tests/Fixtures/Common/plex/friends.json — 3 friends, first plexuser2
|
| The user endpoint host is `plex.tv` (not `clients.plex.tv`), so its fake
| pattern is anchored to `https://plex.tv/api/v2/user*` to avoid swallowing the
| clients host.
*/

it('returns the trimmed account shape from GET plex.tv/api/v2/user', function (): void {
    Http::fake([
        'https://plex.tv/api/v2/user*' => Http::response(fixtureBytes('Common/plex/user.json')),
    ]);

    $info = resolve(PlexApiService::class)->getUserInfo('the-token');

    expect($info)->toBe([
        'id' => 1001,
        'uuid' => '0000000000000001',
        'username' => 'plexuser1',
        'email' => 'user1@example.com',
        'thumb' => 'https://plex.tv/users/aaaaaaaaaaaaaaaa/avatar?c=1',
    ]);
});

it('sends the user request with X-Plex-Token and no Authorization header', function (): void {
    Http::fake([
        'https://plex.tv/api/v2/user*' => Http::response(fixtureBytes('Common/plex/user.json')),
    ]);

    resolve(PlexApiService::class)->getUserInfo('the-token');

    Http::assertSent(fn ($request): bool => $request->hasHeader('X-Plex-Token', 'the-token')
        && ! $request->hasHeader('Authorization'));
});

it('returns a Collection of the 3 friends from GET clients.plex.tv/api/v2/friends', function (): void {
    Http::fake([
        '*clients.plex.tv/api/v2/friends*' => Http::response(fixtureBytes('Common/plex/friends.json')),
    ]);

    $friends = resolve(PlexApiService::class)->getFriends('the-token');

    expect($friends)->toBeInstanceOf(Collection::class)
        ->and($friends->count())->toBe(3)
        ->and($friends->first()['username'])->toBe('plexuser2');
});
