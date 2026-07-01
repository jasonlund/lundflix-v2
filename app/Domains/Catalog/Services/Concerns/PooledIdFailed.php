<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Services\Concerns;

use Exception;

/**
 * Internal control-flow signal for {@see PoolsIdBatches}: a service's
 * {@see PoolsIdBatches::resolvePooled()} throws this to tell the pooling loop
 * "collect this id as a per-id failure and keep going". It never escapes the
 * loop — the loop catches it and aggregates the id into the service's typed
 * `*RequestFailed::forIds`. Real auth/request failures are NOT this sentinel,
 * so they propagate immediately.
 */
final class PooledIdFailed extends Exception {}
