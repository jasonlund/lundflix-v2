<?php

declare(strict_types=1);

namespace App\Domains\Identity\Exceptions;

use Exception;

final class PlexAccountLacksServerAccess extends Exception
{
    /**
     * @param  array{id: int|null, uuid: string|null, username: string|null, email: string|null, thumb: string|null}  $plexUser  from PlexApiService::getUserInfo()
     */
    public static function for(array $plexUser): self
    {
        return new self(sprintf(
            'Plex auth failed: account has no server access (plex_id=%s, plex_username=%s, plex_email=%s).',
            $plexUser['id'] ?? 'null',
            $plexUser['username'] ?? 'null',
            $plexUser['email'] ?? 'null',
        ));
    }
}
