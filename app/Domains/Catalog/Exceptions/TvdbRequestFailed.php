<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Exceptions;

use Exception;

class TvdbRequestFailed extends Exception
{
    public static function for(string $url): self
    {
        return new self("TheTVDB request to [{$url}] failed after retries.");
    }
}
