<?php

declare(strict_types=1);

use App\Domains\Common\Data\PlexAccount;
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
|   getUserInfo($token) — GET plex.tv/api/v2/user, returns a PlexAccount DTO
|     (FLIX-243 slice 2, was an array); the request carries X-Plex-Token and
|     never an Authorization header.
|   getFriends($token)  — GET clients.plex.tv/api/v2/friends, returns a Collection
|     of the 3 friends.
|
| Fixtures (byte-exact real captures):
|   tests/Fixtures/Common/plex/user.json    — account 1001 / plexuser1
|   tests/Fixtures/Common/plex/friends.json — 3 friends, first plexuser2
|
| The one synthetic body is the partial account (only an id): every real Plex
| account carries uuid/username/email/thumb, so a real capture cannot exercise
| the omitted-field nulls the DTO has to carry.
|
| The user endpoint host is `plex.tv` (not `clients.plex.tv`), so its fake
| pattern is anchored to `https://plex.tv/api/v2/user*` to avoid swallowing the
| clients host.
*/

describe('getUserInfo() account read', function (): void {
    it('returns a PlexAccount from GET plex.tv/api/v2/user', function (): void {
        // Arrange
        Http::fake([
            'https://plex.tv/api/v2/user*' => Http::response(fixtureBytes('Common/plex/user.json')),
        ]);

        // Act
        $account = resolve(PlexApiService::class)->getUserInfo('the-token');

        // Assert
        expect($account)->toBeInstanceOf(PlexAccount::class)
            ->and($account->id)->toBe(1001)
            ->and($account->uuid)->toBe('0000000000000001')
            ->and($account->username)->toBe('plexuser1')
            ->and($account->email)->toBe('user1@example.com')
            ->and($account->thumb)->toBe('https://plex.tv/users/aaaaaaaaaaaaaaaa/avatar?c=1');
    });

    it('nulls every PlexAccount field the account body omits', function (): void {
        // Arrange
        Http::fake([
            'https://plex.tv/api/v2/user*' => Http::response(['id' => 1001]),
        ]);

        // Act
        $account = resolve(PlexApiService::class)->getUserInfo('the-token');

        // Assert
        expect($account)->toBeInstanceOf(PlexAccount::class)
            ->and($account->id)->toBe(1001)
            ->and($account->uuid)->toBeNull()
            ->and($account->username)->toBeNull()
            ->and($account->email)->toBeNull()
            ->and($account->thumb)->toBeNull();
    });

    it('sends the user request with X-Plex-Token and no Authorization header', function (): void {
        Http::fake([
            'https://plex.tv/api/v2/user*' => Http::response(fixtureBytes('Common/plex/user.json')),
        ]);

        resolve(PlexApiService::class)->getUserInfo('the-token');

        Http::assertSent(fn ($request): bool => $request->hasHeader('X-Plex-Token', 'the-token')
            && ! $request->hasHeader('Authorization'));
    });
});

describe('getFriends() friend list', function (): void {
    it('returns a Collection of the 3 friends from GET clients.plex.tv/api/v2/friends', function (): void {
        Http::fake([
            '*clients.plex.tv/api/v2/friends*' => Http::response(fixtureBytes('Common/plex/friends.json')),
        ]);

        $friends = resolve(PlexApiService::class)->getFriends('the-token');

        expect($friends)->toBeInstanceOf(Collection::class)
            ->and($friends->count())->toBe(3)
            ->and($friends->first()['username'])->toBe('plexuser2');
    });
});
