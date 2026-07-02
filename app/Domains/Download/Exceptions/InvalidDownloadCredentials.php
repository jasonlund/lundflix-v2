<?php

declare(strict_types=1);

namespace App\Domains\Download\Exceptions;

use Exception;

class InvalidDownloadCredentials extends Exception
{
    public static function loginPageReturned(): self
    {
        return new self('Download login page returned — check the uid/pass download cookie credentials.');
    }
}
