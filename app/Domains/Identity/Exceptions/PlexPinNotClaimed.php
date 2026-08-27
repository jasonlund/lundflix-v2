<?php

declare(strict_types=1);

namespace App\Domains\Identity\Exceptions;

use Exception;

final class PlexPinNotClaimed extends Exception
{
    public static function for(int $pinId): self
    {
        return new self(sprintf('Plex auth failed: no token for pin_id=%d.', $pinId));
    }
}
