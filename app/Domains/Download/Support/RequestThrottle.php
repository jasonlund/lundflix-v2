<?php

declare(strict_types=1);

namespace App\Domains\Download\Support;

use App\Domains\Download\Exceptions\RateLimitExceeded;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Sleep;

final class RequestThrottle
{
    private const int SPACING_MS = 6500;

    private const string SLOT_KEY = 'download:request-throttle:next-slot';

    private const string LOCK_KEY = 'download:request-throttle:lock';

    private const int MAX_WAIT_MS = 30000;

    private const int FALLBACK_RETRY_AFTER_MS = 60000;

    /**
     * Block until this caller's spaced slot is free, then claim the next one.
     *
     * The read-compute-write of the shared slot runs under a lock so concurrent
     * workers can't read the same slot and fire together; the slot is a
     * perpetual cursor (never a TTL), hence forever.
     */
    public function await(): void
    {
        $this->underLock(function (): void {
            [$now, $nextSlot] = $this->currentSlot();

            $waitMs = $nextSlot - $now;

            // A 429 cooldown longer than the cap surfaces as a typed failure for
            // the caller to handle, rather than an unbounded blocking sleep.
            if ($waitMs > self::MAX_WAIT_MS) {
                throw RateLimitExceeded::afterWaiting($waitMs);
            }

            if ($waitMs > 0) {
                Sleep::for($waitMs)->milliseconds();
            }

            Cache::forever(self::SLOT_KEY, max($now, $nextSlot) + self::SPACING_MS);
        });
    }

    /**
     * Push the next available slot out by the server-supplied retry-after (or a
     * 60s fallback when the response gives no hint), so the following await()
     * honours the backoff instead of firing immediately.
     */
    public function backoff(?int $retryAfterSeconds = null): void
    {
        $this->underLock(function () use ($retryAfterSeconds): void {
            $retryAfterMs = $retryAfterSeconds !== null
                ? $retryAfterSeconds * 1000
                : self::FALLBACK_RETRY_AFTER_MS;

            [$now, $currentSlot] = $this->currentSlot();

            Cache::forever(self::SLOT_KEY, max($currentSlot, $now + $retryAfterMs));
        });
    }

    /**
     * Run the slot mutation under the shared lock, mapping a lock-acquisition
     * timeout to the domain throttle failure.
     *
     * Under multi-worker Horizon contention block() throws the framework's
     * LockTimeoutException; callers only catch RateLimitExceeded, and both mean
     * "the throttle did not admit this request — back off", so it is rethrown as
     * the domain exception (preserving the original as $previous) rather than
     * escaping as an unhandled 500.
     */
    private function underLock(callable $callback): void
    {
        try {
            Cache::lock(self::LOCK_KEY, 10)->block(5, $callback);
        } catch (LockTimeoutException $e) {
            throw RateLimitExceeded::fromLockContention($e);
        }
    }

    /**
     * The current wall-clock and the slot cursor (defaulting to now when unset),
     * both in milliseconds — the shared read both await() and backoff() open with.
     *
     * @return array{int, int}
     */
    private function currentSlot(): array
    {
        $now = now()->getTimestampMs();

        return [$now, (int) Cache::get(self::SLOT_KEY, $now)];
    }
}
