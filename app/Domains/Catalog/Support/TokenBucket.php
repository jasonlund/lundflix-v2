<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Support;

use Closure;

/**
 * Paces requests for the shared pooled transport: it computes how long a caller
 * must wait for its next slot, and never sleeps itself, so the arithmetic stays
 * testable and the caller keeps ownership of how it waits.
 *
 * Not `readonly` — a pacer is state by definition (a cursor, the rate in force,
 * the instant of the last penalty), which a readonly class cannot hold. It is
 * exempted from the arch suite's readonly rule by name.
 */
final class TokenBucket
{
    /** How long the halved rate holds before AIMD recovery starts climbing it back. */
    private const int PENALTY_HOLD_SECONDS = 30;

    /** Requests per second added per second of climb, once the hold has expired. */
    private const float CLIMB_PER_SECOND = 2.0;

    /** The instant the next token comes due; null until the first token is taken. */
    private ?float $cursor = null;

    /** The rate the last penalty cut to, and when — null while no penalty is in force. */
    private ?float $penalizedRate = null;

    private ?float $penalizedAt = null;

    /**
     * @param  ?float  $target  requests per second, or null for an unlimited bucket
     * @param  ?Closure(): float  $clock  monotonic seconds; defaults to microtime(true)
     */
    public function __construct(private readonly ?float $target, private readonly ?Closure $clock = null) {}

    /** Seconds the caller must wait before spending the token this call reserves. */
    public function waitFor(): float
    {
        $rate = $this->rate();

        if ($rate === null) {
            return 0.0;
        }

        $now = $this->now();

        // A cursor left behind by the clock means the bucket refilled while nobody
        // was asking, so the token is free and the cursor restarts from now.
        $due = max($this->cursor ?? $now, $now);

        $this->cursor = $due + (1.0 / $rate);

        return $due - $now;
    }

    /** The rate currently in force, which a penalty may have pushed below the target. */
    public function rate(): ?float
    {
        if ($this->target === null || $this->penalizedRate === null || $this->penalizedAt === null) {
            return $this->target;
        }

        $climbing = $this->now() - $this->penalizedAt - self::PENALTY_HOLD_SECONDS;

        if ($climbing <= 0.0) {
            return $this->penalizedRate;
        }

        return min($this->penalizedRate + (self::CLIMB_PER_SECOND * $climbing), $this->target);
    }

    /**
     * Record upstream push-back (a 429) against the current rate.
     *
     * The hold is rate state measured against the clock, never a sleep: this pacer
     * fronts one shared connection, so pausing here would stall every in-flight
     * stream rather than just the caller that was pushed back.
     */
    public function penalize(): void
    {
        $rate = $this->rate();

        if ($rate === null) {
            return;
        }

        $this->penalizedRate = $rate / 2.0;
        $this->penalizedAt = $this->now();
    }

    private function now(): float
    {
        return $this->clock instanceof Closure ? ($this->clock)() : microtime(true);
    }
}
