<?php

namespace App\Domains\Catalog\Exceptions;

use Exception;

class TmdbRequestFailed extends Exception
{
    public static function for(string $url): self
    {
        return new self("TMDB request to [{$url}] failed after retries.");
    }

    /**
     * @param  array<int, int|string>  $ids
     */
    public static function forIds(array $ids): self
    {
        return new self('TMDB batch request failed for ids ['.implode(', ', $ids).'] after retries.');
    }
}
