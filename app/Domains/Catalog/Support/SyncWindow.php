<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Support;

use Carbon\CarbonImmutable;

/**
 * One sync interval expressed in each source's native date shape:
 * TVDB's updates endpoint wants a unix timestamp, TMDB's changes
 * endpoint wants Y-m-d start/end dates.
 */
final readonly class SyncWindow
{
    public function __construct(
        public CarbonImmutable $since,
        public CarbonImmutable $until,
    ) {}

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
