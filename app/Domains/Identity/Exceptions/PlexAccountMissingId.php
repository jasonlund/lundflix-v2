<?php

declare(strict_types=1);

namespace App\Domains\Identity\Exceptions;

use Exception;

final class PlexAccountMissingId extends Exception
{
    public static function for(int $pinId): self
    {
        return new self(sprintf('Plex auth failed: verified account carried no id (pin_id=%d).', $pinId));
    }
}
