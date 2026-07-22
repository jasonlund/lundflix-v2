<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Console\Commands;

use App\Domains\Catalog\Actions\SeedTvdbEpisodes;
use App\Domains\Catalog\Enums\SyncFeed;
use App\Domains\Catalog\Exceptions\TvdbAuthenticationFailed;
use App\Domains\Catalog\Exceptions\TvdbRequestFailed;
use App\Domains\Catalog\Models\Show;
use App\Domains\Catalog\Services\TvdbApiService;
use App\Domains\Catalog\Support\SyncMarker;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Incremental TheTVDB episodes sync for already-seeded shows. The /updates `since`
 * is derived from the feed's persisted marker: a 6h overlap re-fetches behind the
 * marker, a first run with no marker reaches back 24h, and the reach is capped at
 * 14 days. A clean, unbounded run advances the marker to its start time; a run with
 * any failure or `--limit` leaves the marker untouched so the missed span is
 * re-covered next run.
 */
#[Description('Sync TheTVDB episodes for already-seeded shows incrementally from the /updates feed since the last run marker')]
#[Signature('catalog:sync-episodes-tvdb {--limit=}')]
class SyncTvdbEpisodes extends Command
{
    public function handle(TvdbApiService $api, SeedTvdbEpisodes $seed, SyncMarker $marker): int
    {
        $startedAt = CarbonImmutable::now();

        $since = $marker->window(SyncFeed::TvdbEpisodes)->sinceTimestamp();

        $seriesIds = collect($api->updates($since, 'episodes'))
            ->pluck('seriesId')
            ->filter(fn ($id): bool => is_numeric($id))
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values();

        $query = Show::query()
            ->whereIn('_tvdb_id', $seriesIds->all())
            ->whereNotNull('episodes_synced_at');

        $limit = $this->option('limit');
        if ($limit !== null) {
            // Order before capping so a --limit run picks a reproducible subset,
            // not whatever arbitrary order the DB happens to return.
            $query->orderBy('id')->limit((int) $limit);
        }

        $failed = false;

        // Forward-only read-stream dispatching per-show work, so there is no
        // offset-pagination skip/double-process hazard for chunkById to guard:
        // the only write (handle() stamps episodes_synced_at) hits a non-key
        // column on the current row and doesn't disturb the cursor's forward
        // scan. Report-and-continue so one bad show can't abort the run.
        foreach ($query->cursor() as $show) {
            try {
                $seed->handle($show);
            } catch (TvdbRequestFailed|TvdbAuthenticationFailed $e) {
                report($e);
                $failed = true;
            }
        }

        // Advance only on a clean, unbounded run: a failed show or a --limit cap means
        // this run didn't cover the whole window, so the marker must not move past it.
        if (! $failed && $this->option('limit') === null) {
            $marker->advance(SyncFeed::TvdbEpisodes, $startedAt);
        }

        return self::SUCCESS;
    }
}
