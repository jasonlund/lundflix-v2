<?php

declare(strict_types=1);

namespace App\Domains\Download\Support;

use App\Domains\Download\Exceptions\RateLimitExceeded;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Sleep;

final readonly class RequestThrottle
{
    private const string SLOT_KEY = 'download:request-throttle:next-slot';

    private const string LOCK_KEY = 'download:request-throttle:lock';

    private const int MIN_GAP_MS = 100;

    private const int MAX_GAP_MS = 250;

    /**
     * Block until this caller's spaced slot is free, then claim the next one.
     *
     * The read-compute-write of the shared slot runs under a lock so concurrent
     * workers can't read the same slot and fire together; the slot is a
     * perpetual cursor (never a TTL), hence forever. Each await advances the
     * cursor by a fresh random 100-250ms so successive requests are jittered
     * rather than fired at a fixed cadence.
     */
    public function await(): void
    {
        $this->underLock(function (): void {
            [$now, $nextSlot] = $this->currentSlot();

            $waitMs = $nextSlot - $now;

            if ($waitMs > 0) {
                Sleep::for($waitMs)->milliseconds();
            }

            Cache::forever(self::SLOT_KEY, max($now, $nextSlot) + random_int(self::MIN_GAP_MS, self::MAX_GAP_MS));
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
     * both in milliseconds — the shared read await() opens with.
     *
     * @return array{int, int}
     */
    private function currentSlot(): array
    {
        $now = now()->getTimestampMs();

        return [$now, (int) Cache::get(self::SLOT_KEY, $now)];
    }
}
