<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Data;

use Carbon\CarbonImmutable;

/**
 * One sync interval expressed in each source's native date shape:
 * TVDB's updates endpoint wants a unix timestamp, TMDB's changes
 * endpoint wants Y-m-d start/end dates.
 */
final readonly class SyncWindow
{
    /**
     * @param  ?CarbonImmutable  $uncoveredSince  The marker-derived start that SyncMarker's
     *                                            CAP_DAYS floor discarded, leaving `[uncoveredSince, since)` never
     *                                            fetched; null when the floor never fired.
     */
    public function __construct(
        public CarbonImmutable $since,
        public CarbonImmutable $until,
        public ?CarbonImmutable $uncoveredSince = null,
    ) {}

    public function isCapped(): bool
    {
        return $this->uncoveredSince instanceof CarbonImmutable;
    }

    public function uncoveredStartDate(): ?string
    {
        return $this->uncoveredSince?->format('Y-m-d');
    }

    public function sinceTimestamp(): int
    {
        return $this->since->timestamp;
    }

    public function startDate(): string
    {
        return $this->since->format('Y-m-d');
    }

    public function endDate(): string
    {
        return $this->until->format('Y-m-d');
    }
}
