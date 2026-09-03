<?php

declare(strict_types=1);

use App\Domains\Common\Data\PlexAccount;
use App\Domains\Identity\Exceptions\PlexAccountAlreadyRegistered;
use App\Domains\Identity\Exceptions\PlexAccountLacksServerAccess;

/*
|--------------------------------------------------------------------------
| Plex account refusal exceptions — messages, built from a PlexAccount
|--------------------------------------------------------------------------
| Both refusals the callback can raise name the offending Plex account in their
| message, and that message is the whole operator-facing value of the class —
| it is what lands in the log when a guest is turned away. These tests pin the
| exact wording — including the literal 'null' each absent field falls back to —
| so it survives FLIX-243 slice 2 swapping the ::for() parameter from an array
| to the PlexAccount DTO. The fallback is pinned only on the no-server-access
| message; the already-registered one renders it through the identical
| expression, so a second case would duplicate one behavior.
|
| No HTTP and no DB: the accounts are constructed directly, since the DTO is
| the input under test rather than anything a fixture would produce.
*/

describe('::for() message wording', function (): void {
    it('names the Plex account in the lacks-server-access message', function (): void {
        // Arrange
        $account = new PlexAccount(1001, '0000000000000001', 'plexuser1', 'user1@example.com', null);

        // Act
        $exception = PlexAccountLacksServerAccess::for($account);

        // Assert
        expect($exception->getMessage())->toBe(
            'Plex auth failed: account has no server access (plex_id=1001, plex_username=plexuser1, plex_email=user1@example.com).'
        );
    });

    it('names the account fields as null when the Plex account carries none', function (): void {
        // Arrange
        $account = new PlexAccount(null, null, null, null, null);

        // Act
        $exception = PlexAccountLacksServerAccess::for($account);

        // Assert
        expect($exception->getMessage())->toBe(
            'Plex auth failed: account has no server access (plex_id=null, plex_username=null, plex_email=null).'
        );
    });

    it('names the Plex account and the existing user in the already-registered message', function (): void {
        // Arrange
        $account = new PlexAccount(1001, '0000000000000001', 'plexuser1', 'user1@example.com', null);

        // Act
        $exception = PlexAccountAlreadyRegistered::for($account, 7);

        // Assert
        expect($exception->getMessage())->toBe(
            'Plex auth failed: account already registered (plex_id=1001, plex_username=plexuser1, plex_email=user1@example.com, existing_user_id=7).'
        );
    });
});
