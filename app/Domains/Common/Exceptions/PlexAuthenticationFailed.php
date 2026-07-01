<?php

declare(strict_types=1);

namespace App\Domains\Common\Exceptions;

use Exception;

class PlexAuthenticationFailed extends Exception
{
    public static function invalidToken(): self
    {
        return new self('Plex authentication failed (401). Check services.plex.* token.');
    }
}
