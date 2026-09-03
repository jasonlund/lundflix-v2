<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Support;

use App\Domains\Catalog\Enums\ImdbDataset;
use Illuminate\Support\Facades\Cache;

final readonly class ImdbDatasetMarker
{
    public function current(ImdbDataset $dataset): ?string
    {
        return Cache::get($dataset->cacheKey());
    }

    /**
     * Persist the dataset's Last-Modified header verbatim.
     *
     * Stored raw so the next run's gate is a plain string comparison against the
     * probed header — no date parsing, no clock, nothing to drift. `forever`
     * because this is durable run-state, not a TTL'd cache value that may
     * evaporate.
     */
    public function advance(ImdbDataset $dataset, string $lastModified): void
    {
        Cache::forever($dataset->cacheKey(), $lastModified);
    }
}
