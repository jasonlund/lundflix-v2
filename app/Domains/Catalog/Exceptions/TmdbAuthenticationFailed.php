<?php

namespace App\Domains\Catalog\Exceptions;

use Exception;

class TmdbAuthenticationFailed extends Exception
{
    public static function invalidToken(): self
    {
        return new self('TMDB authentication failed (401). Check services.tmdb.token.');
    }
}
