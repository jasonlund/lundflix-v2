<?php

declare(strict_types=1);

namespace App\Domains\PlexLibrary\Exceptions;

use Exception;

final class ConfiguredPlexServerUnavailable extends Exception
{
    public static function forIdentifier(string $id): self
    {
        return new self("No online Plex server matches the configured identifier [{$id}].");
    }
}
