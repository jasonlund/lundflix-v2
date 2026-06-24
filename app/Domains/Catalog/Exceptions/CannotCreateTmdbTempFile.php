<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Exceptions;

use Exception;

class CannotCreateTmdbTempFile extends Exception
{
    public static function inTempDir(string $dir): self
    {
        return new self("A temp file for the TMDB export could not be created in [{$dir}].");
    }
}
