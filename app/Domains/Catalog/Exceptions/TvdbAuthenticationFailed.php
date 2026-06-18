<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Exceptions;

use Exception;

class TvdbAuthenticationFailed extends Exception
{
    public static function invalidToken(): self
    {
        return new self('TheTVDB authentication failed (401). Check services.tvdb.key.');
    }
}
