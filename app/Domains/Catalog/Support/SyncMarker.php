<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Support;

use App\Domains\Catalog\Data\SyncWindow;
use App\Domains\Catalog\Enums\SyncFeed;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

final class SyncMarker
{
    private const int OVERLAP_HOURS = 6;

    private const int FALLBACK_HOURS = 24;

    private const int CAP_DAYS = 14;

    /**
     * The incremental window to fetch, resolved from the feed's marker at run start.
     *
     * The 6h overlap re-fetches behind the marker so an update straddling two runs
     * isn't missed. With no marker (first run) we reach back 24h. The 14-day cap
     * both floors a stale marker and keeps requests within TMDB's ≤14-day span.
     *
     * The span the cap discards is carried forward on the window: it is never fetched
     * and never retried, so the only way a leg can report the gap is to be handed it.
     */
    public function window(SyncFeed $feed): SyncWindow
    {
        $now = CarbonImmutable::now();
        $marker = Cache::get($feed->cacheKey());

        // Only a string is readable. `cache.serializable_classes` is false, so a marker
        // written as an object by an older build unserializes to __PHP_Incomplete_Class —
        // anything that isn't a string counts as no marker, and this run's advance()
        // overwrites it rather than needing a manual forget.
        $since = is_string($marker)
            ? CarbonImmutable::parse($marker)->subHours(self::OVERLAP_HOURS)
            : $now->subHours(self::FALLBACK_HOURS);

        $floor = $now->subDays(self::CAP_DAYS);
        $uncoveredSince = null;
        if ($since->lessThan($floor)) {
            $uncoveredSince = $since;
            $since = $floor;
        }

        return new SyncWindow($since, $now, $uncoveredSince);
    }

    /**
     * Persist the run-START time as the new marker.
     *
     * We store when the run began (captured before fetching), not when it ended, so
     * updates that land mid-run are re-covered by the next run's window. `forever`
     * because this is durable run-state, not a TTL'd cache value that may evaporate.
     *
     * Persisted as an ISO-8601 string, never the Carbon itself: no object survives the
     * cache round trip under `cache.serializable_classes`, so storing one makes the
     * marker write-only. The offset the format carries keeps parse() exact.
     */
    public function advance(SyncFeed $feed, CarbonImmutable $startedAt): void
    {
        Cache::forever($feed->cacheKey(), $startedAt->toIso8601String());
    }
}
