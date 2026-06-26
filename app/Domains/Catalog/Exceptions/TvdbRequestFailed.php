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

    /**
     * @param  array<int, int|string>  $ids
     */
    public static function forIds(array $ids): self
    {
        return new self('TheTVDB batch request failed for ids ['.implode(', ', $ids).'] after retries.');
    }
}
