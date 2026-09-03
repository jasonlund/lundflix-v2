<?php

declare(strict_types=1);

namespace App\Domains\Common\Exceptions;

use Exception;

final class PlexRequestFailed extends Exception
{
    public static function for(string $url): self
    {
        return new self("Plex request to [{$url}] failed after retries.");
    }
}
