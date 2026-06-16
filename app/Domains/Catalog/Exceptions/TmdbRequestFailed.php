<?php

namespace App\Domains\Catalog\Exceptions;

use Exception;

class TmdbRequestFailed extends Exception
{
    public static function for(string $url): self
    {
        return new self("TMDB request to [{$url}] failed after retries.");
    }

    public static function authFailed(): self
    {
        return new self('TMDB authentication failed (401). Check services.tmdb.token.');
    }
}
