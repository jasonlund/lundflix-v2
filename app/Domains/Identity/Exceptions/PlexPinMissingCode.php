<?php

declare(strict_types=1);

namespace App\Domains\Identity\Exceptions;

use Exception;

class PlexPinMissingCode extends Exception
{
    public static function onStart(int|string|null $pinId): self
    {
        return new self(sprintf('Plex auth failed: minted PIN carried no code (pin_id=%s).', $pinId ?? 'null'));
    }
}
