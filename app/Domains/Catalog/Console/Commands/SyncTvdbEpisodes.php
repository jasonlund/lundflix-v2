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
use Illuminate\Database\Eloquent\Collection;

/**
 * Incremental TheTVDB episodes sync for already-seeded shows. The /updates `since`
 * is derived from the feed's persisted marker: a 6h overlap re-fetches behind the
 * marker, a first run with no marker reaches back 24h, and the reach is capped at
 * 14 days. A clean run advances the marker to its start time; a run with any
 * failure leaves the marker untouched so the missed span is re-covered next run.
 */
#[Description('Sync TheTVDB episodes for already-seeded shows incrementally from the /updates feed since the last run marker')]
#[Signature('catalog:sync-episodes-tvdb')]
final class SyncTvdbEpisodes extends Command
{
    public function handle(TvdbApiService $api, SeedTvdbEpisodes $seed, SyncMarker $marker): int
    {
        $startedAt = CarbonImmutable::now();

        $since = $marker->window(SyncFeed::TvdbEpisodes)->sinceTimestamp();

        // Drain the feed into an int set keyed by id: the key dedupes for free and
        // holds only ints, so the whole feed never sits in memory as records. The
        // set is fully drained before any lookup runs — a mid-feed page failure
        // must abort the run with no show processed.
        $seen = [];

        $this->output->writeln('Reading the episodes update feed…');

        foreach ($api->updates($since, 'episodes') as $record) {
            $seriesId = $record['seriesId'] ?? null;

            if (is_numeric($seriesId)) {
                $seen[(int) $seriesId] = true;
            }
        }

        $failed = false;

        $this->output->writeln('Syncing episodes…');

        // Per 100 rather than the other Catalog syncs' per 1000: those beat over
        // bulk-hydrated batches, while this walk pays a paged HTTP crawl per show,
        // so items land orders of magnitude slower — the same reason the Plex
        // per-show episode crawl beats per 100.
        $episodes = 0;
        $beatAt = 100;

        // The feed carries far more distinct ids than a single whereIn can bind, so
        // the membership lookup runs a chunk at a time.
        foreach (array_chunk(array_keys($seen), 1000) as $chunk) {
            $query = Show::query()
                ->select(['id', '_tvdb_id', '_tvdb_defaultSeasonType'])
                ->whereIn('_tvdb_id', $chunk)
                ->whereNotNull('episodes_synced_at');

            // PK pagination because the walk writes to the rows it iterates:
            // handle() stamps episodes_synced_at, which offset pagination would
            // skip or double-process. Report-and-continue so one bad show can't
            // abort the run.
            $query->chunkById(200, function (Collection $shows) use ($seed, &$failed, &$episodes, &$beatAt): void {
                foreach ($shows as $show) {
                    try {
                        $episodes += $seed->handle($show);
                    } catch (TvdbRequestFailed|TvdbAuthenticationFailed $e) {
                        report($e);
                        $failed = true;
                    }

                    if ($episodes >= $beatAt) {
                        $this->output->writeln("  [episodes {$episodes}]");
                        $beatAt = intdiv($episodes, 100) * 100 + 100;
                    }
                }
            });
        }

        // Advance only on a clean run: a failed show means this run didn't cover
        // the whole window, so the marker must not move past it.
        if (! $failed) {
            $marker->advance(SyncFeed::TvdbEpisodes, $startedAt);
        }

        return self::SUCCESS;
    }
}
