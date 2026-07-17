<?php

declare(strict_types=1);

namespace App\Domains\Common\Exceptions;

use Exception;

class PlexServerIdentifierMissing extends Exception
{
    public static function notConfigured(): self
    {
        return new self('PLEX_SERVER_IDENTIFIER is not configured.');
    }
}
