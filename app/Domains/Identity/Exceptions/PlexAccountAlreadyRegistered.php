<?php

declare(strict_types=1);

namespace App\Domains\Identity\Exceptions;

use App\Domains\Common\Data\PlexAccount;
use Exception;

final class PlexAccountAlreadyRegistered extends Exception
{
    public static function for(PlexAccount $account, int|string $existingUserId): self
    {
        return new self(sprintf(
            'Plex auth failed: account already registered (plex_id=%s, plex_username=%s, plex_email=%s, existing_user_id=%s).',
            $account->id ?? 'null',
            $account->username ?? 'null',
            $account->email ?? 'null',
            $existingUserId,
        ));
    }
}
