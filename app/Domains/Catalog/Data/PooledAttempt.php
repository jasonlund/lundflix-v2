<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Data;

use App\Domains\Catalog\Services\PooledTransport;
use Closure;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;

/**
 * One dispatch of one key through {@see PooledTransport}: the caller's key, the
 * builder that turns a pooled request into that key's request, and which
 * attempt this is. A re-queue is a new attempt over the same key and builder,
 * so the builder is kept for the life of the batch rather than consumed.
 */
final readonly class PooledAttempt
{
    /**
     * @param  Closure(PendingRequest): (Response|PromiseInterface)  $build
     */
    public function __construct(
        public int|string $key,
        public Closure $build,
        public int $number,
    ) {}
}
