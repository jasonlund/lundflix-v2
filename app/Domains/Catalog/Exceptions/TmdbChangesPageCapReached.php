<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Exceptions;

use Exception;

final class TmdbChangesPageCapReached extends Exception
{
    public static function on(string $path, string $day, int $totalPages, int $readablePages): self
    {
        return new self("TMDB changes feed [{$path}] for {$day} reports {$totalPages} pages; only the first {$readablePages} can be read.");
    }
}
