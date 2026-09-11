<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Support;

use App\Domains\Catalog\Data\SyncWindow;
use App\Domains\Catalog\Enums\SyncFeed;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final readonly class SyncMarker
{
    private const string TABLE = 'catalog_sync_markers';

    /**
     * Where the feed markers lived before this table. Named only here — the backfill
     * is the sole reader, and `SyncFeed` no longer names a cache.
     *
     * Only the four `SyncFeed` suffixes under this prefix are retired: `ImdbDataset`
     * keys its own still-live markers off the same string, so a blanket sweep of the
     * prefix would cost the IMDb datasets theirs.
     */
    private const string LEGACY_CACHE_PREFIX = 'catalog:sync:marker:';

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
        $marker = $this->markedAt($feed);

        $since = $marker instanceof CarbonImmutable
            ? $marker->subHours(self::OVERLAP_HOURS)
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
     * updates that land mid-run are re-covered by the next run's window.
     *
     * Upserted on the unique `feed`, so a leg's marker is one row for the table's
     * lifetime. The query builder writes no timestamps of its own, so both are set
     * here — `created_at` only lands on the insert branch.
     */
    public function advance(SyncFeed $feed, CarbonImmutable $startedAt): void
    {
        $now = CarbonImmutable::now();

        DB::table(self::TABLE)->upsert(
            [[
                'feed' => $feed->key(),
                'marked_at' => $startedAt,
                'created_at' => $now,
                'updated_at' => $now,
            ]],
            ['feed'],
            ['marked_at', 'updated_at'],
        );
    }

    /**
     * Backfill the marker table from the legacy `Cache::forever` entries, returning
     * how many feeds were carried over.
     *
     * A feed whose cached value can't be read is skipped rather than defaulted or
     * thrown on: this runs inside a migration, so an unreadable leftover must cost
     * that one feed its marker (it falls back to the 24h window) and nothing more.
     */
    public function importFromCache(): int
    {
        $imported = 0;

        foreach (SyncFeed::cases() as $feed) {
            $markedAt = $this->parseInstant(Cache::get(self::LEGACY_CACHE_PREFIX.$feed->key()));

            if (! $markedAt instanceof CarbonImmutable) {
                continue;
            }

            $this->advance($feed, $markedAt);
            $imported++;
        }

        return $imported;
    }

    /**
     * The feed's stored `marked_at`, or null when the feed has no row yet or the
     * stored value can't be read.
     */
    private function markedAt(SyncFeed $feed): ?CarbonImmutable
    {
        return $this->parseInstant(DB::table(self::TABLE)->where('feed', $feed->key())->value('marked_at'));
    }

    /**
     * Parse a stored instant, or null when the value can't be trusted.
     *
     * Deliberately defensive about both sources it serves. The column is only as good
     * as whatever put a value there, and the legacy cache entry can come back as
     * `__PHP_Incomplete_Class` — `cache.serializable_classes` is false, so an object
     * an older build wrote never survives the round trip (FLIX-287). Either way an
     * unreadable marker degrades to the no-marker path rather than failing the run.
     */
    private function parseInstant(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || Str::trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            return null;
        }
    }
}
