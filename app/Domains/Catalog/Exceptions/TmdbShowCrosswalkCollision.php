<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Exceptions;

use Exception;

final class TmdbShowCrosswalkCollision extends Exception
{
    public static function forResolvedId(string $imdbId, int $tmdbId): self
    {
        return new self("Show [{$imdbId}] resolved to tmdb id [{$tmdbId}] already held by another row.");
    }
}
