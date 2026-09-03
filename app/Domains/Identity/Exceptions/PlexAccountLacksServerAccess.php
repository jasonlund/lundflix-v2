<?php

declare(strict_types=1);

namespace App\Domains\Identity\Exceptions;

use App\Domains\Common\Data\PlexAccount;
use Exception;

final class PlexAccountLacksServerAccess extends Exception
{
    public static function for(PlexAccount $account): self
    {
        return new self(sprintf(
            'Plex auth failed: account has no server access (plex_id=%s, plex_username=%s, plex_email=%s).',
            $account->id ?? 'null',
            $account->username ?? 'null',
            $account->email ?? 'null',
        ));
    }
}
