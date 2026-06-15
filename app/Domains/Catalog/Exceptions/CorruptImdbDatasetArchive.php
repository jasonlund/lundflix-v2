<?php

namespace App\Domains\Catalog\Exceptions;

use Exception;

class CorruptImdbDatasetArchive extends Exception
{
    public static function at(string $path): self
    {
        return new self("The IMDB dataset archive at [{$path}] is not a valid gzip file.");
    }
}
