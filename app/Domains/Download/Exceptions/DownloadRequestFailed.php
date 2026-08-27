<?php

declare(strict_types=1);

namespace App\Domains\Download\Exceptions;

use Exception;

final class DownloadRequestFailed extends Exception
{
    public static function for(string $url): self
    {
        return new self("Download request to [{$url}] failed.");
    }
}
