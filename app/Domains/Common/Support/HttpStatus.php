<?php

declare(strict_types=1);

namespace App\Domains\Common\Support;

final readonly class HttpStatus
{
    /**
     * The one definition of a transient HTTP status: a 429 or any 5xx. Both
     * retry paths — the global middleware on single requests and the pooled
     * transport's own re-queue — judge a response by this, so they cannot drift
     * apart.
     */
    public static function isRetryable(int $status): bool
    {
        return $status === 429 || $status >= 500;
    }
}
