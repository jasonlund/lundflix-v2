<?php

declare(strict_types=1);

namespace App\Domains\Identity\Exceptions;

use Exception;

class PlexPinMissingFromSession extends Exception
{
    public static function onCallback(): self
    {
        return new self('Plex auth failed: missing plex_pin_id from session.');
    }
}
