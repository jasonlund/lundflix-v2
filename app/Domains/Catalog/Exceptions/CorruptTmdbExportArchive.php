<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Exceptions;

use Exception;

class CorruptTmdbExportArchive extends Exception
{
    public static function at(string $path): self
    {
        return new self("The TMDB export archive at [{$path}] is not a valid gzip file.");
    }
}
