<?php

namespace App\Domains\Catalog\Exceptions;

use Exception;

class CannotOpenImdbDatasetArchive extends Exception
{
    public static function at(string $path): self
    {
        return new self("Unable to open IMDB dataset archive at [{$path}].");
    }
}
