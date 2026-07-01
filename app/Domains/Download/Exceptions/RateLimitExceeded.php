<?php

declare(strict_types=1);

namespace App\Domains\Download\Exceptions;

use Exception;

final class RateLimitExceeded extends Exception
{
    public static function afterWaiting(int $waitMs): self
    {
        return new self("Download crawl rate limit exceeded: required wait of {$waitMs}ms exceeds the 30000ms cap.");
    }
}
