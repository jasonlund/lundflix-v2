<?php

declare(strict_types=1);

namespace App\Domains\Download\Support;

use App\Domains\Download\Exceptions\RateLimitExceeded;
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
        Cache::lock(self::LOCK_KEY, 10)->block(5, function (): void {
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
        Cache::lock(self::LOCK_KEY, 10)->block(5, function () use ($retryAfterSeconds): void {
            $retryAfterMs = $retryAfterSeconds !== null
                ? $retryAfterSeconds * 1000
                : self::FALLBACK_RETRY_AFTER_MS;

            [$now, $currentSlot] = $this->currentSlot();

            Cache::forever(self::SLOT_KEY, max($currentSlot, $now + $retryAfterMs));
        });
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
