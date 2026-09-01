<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Console\Commands;

use App\Domains\Catalog\Actions\SeedTvdbEpisodes;
use App\Domains\Catalog\Console\Commands\Concerns\MeasuresElapsedTime;
use App\Domains\Catalog\Enums\SyncFeed;
use App\Domains\Catalog\Exceptions\TvdbAuthenticationFailed;
use App\Domains\Catalog\Exceptions\TvdbRequestFailed;
use App\Domains\Catalog\Models\Show;
use App\Domains\Catalog\Services\TvdbApiService;
use App\Domains\Catalog\Support\SyncMarker;
use App\Domains\Common\Console\Concerns\EmitsHeartbeat;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

/**
 * Incremental TheTVDB episodes sync for already-seeded shows. The /updates `since`
 * is derived from the feed's persisted marker: a 6h overlap re-fetches behind the
 * marker, a first run with no marker reaches back 24h, and the reach is capped at
 * 14 days. A clean run advances the marker to its start time; a run with any
 * failure leaves the marker untouched so the missed span is re-covered next run.
 */
#[Description('Sync TheTVDB episodes for already-seeded shows incrementally from the /updates feed since the last run marker')]
#[Signature('catalog:sync-episodes-tvdb')]
class SyncTvdbEpisodes extends Command
{
    use EmitsHeartbeat;
    use MeasuresElapsedTime;

    /** Heartbeat tag, source-prefixed so a line names which pipeline emitted it. */
    private const string HEARTBEAT_TAG = 'tvdb episodes';

    /**
     * The feed drain's own tag. It beats only to prove liveness on a long walk, so
     * it deliberately gets no closing flushTotal — a ragged total on every small
     * run would be noise.
     */
    private const string FEED_HEARTBEAT_TAG = 'tvdb feed';

    public function handle(TvdbApiService $api, SeedTvdbEpisodes $seed, SyncMarker $marker): int
    {
        $startedAt = CarbonImmutable::now();

        $since = $marker->window(SyncFeed::TvdbEpisodes)->sinceTimestamp();

        $this->output->writeln('Reading the episodes update feed…');

        $seriesIds = $this->drainFeed($api, $since);

        $this->output->writeln('Read the episodes update feed in '.$this->secondsSince($startedAt).'s');

        $syncStartedAt = CarbonImmutable::now();

        $this->output->writeln('Syncing episodes…');

        // The leg writes no searchable content: the only model save it makes is the
        // per-show episodes_synced_at stamp, whose inline Searchable sync would ship
        // one engine write per show for a bookkeeping column nothing searches. Scout
        // has no global off switch; disabling is per-class, via the trait's static.
        ['episodes' => $episodes, 'failures' => $failures] = Show::withoutSyncingToSearch(
            fn (): array => $this->seedMatchedShows($seriesIds, $seed),
        );

        $this->output->writeln("Synced {$episodes} ".Str::plural('episode', $episodes).' in '.$this->secondsSince($syncStartedAt).'s');

        // Advance only on a clean run: a failed show means this run didn't cover
        // the whole window, so the marker must not move past it.
        if ($failures === 0) {
            $marker->advance(SyncFeed::TvdbEpisodes, $startedAt);
        }

        // Close the run's output: the tag's exact final total (which a crossing-value
        // mark can't supply), then Done.
        $this->flushTotal(self::HEARTBEAT_TAG, $episodes);

        $this->failureSummary($failures, 'shows', 'marker not advanced');

        $this->output->writeln('Done.');

        return $failures > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Fully drained before any lookup runs: a mid-feed page failure must abort the
     * run with no show processed.
     *
     * Accumulated as an int set keyed by id — the key dedupes for free and holds
     * only ints, so the whole feed never sits in memory as records.
     *
     * @return list<int> every distinct series id the feed reported
     */
    private function drainFeed(TvdbApiService $api, int $since): array
    {
        $seen = [];
        $records = 0;

        foreach ($api->updates($since, 'episodes') as $record) {
            $seriesId = $record['seriesId'] ?? null;

            if (is_numeric($seriesId)) {
                $seen[(int) $seriesId] = true;
            }

            $this->beat(self::FEED_HEARTBEAT_TAG, ++$records, 10_000);
        }

        return array_keys($seen);
    }

    /**
     * The failure count gates both the marker advance and the exit code: the
     * narrowed catch below is the only failure this walk tolerates, so counting it
     * says everything a separate "did anything fail" flag would.
     *
     * @param  list<int>  $seriesIds  every distinct series id the feed reported
     * @return array{episodes: int, failures: int}
     */
    private function seedMatchedShows(array $seriesIds, SeedTvdbEpisodes $seed): array
    {
        $failures = 0;

        // Per 100 rather than the other Catalog syncs' per 1000: those beat over
        // bulk-hydrated batches, while this walk pays a paged HTTP crawl per show,
        // so items land orders of magnitude slower — the same reason the Plex
        // per-show episode crawl beats per 100.
        //
        // The cadence is hand-rolled on purpose; do NOT collapse it into beat().
        // A show contributes many episodes at once, so the total jumps past a
        // boundary rather than landing on it, and the operator needs the count as
        // it actually stands: this prints the crossing value (102), where beat()
        // would print the boundary (100) and go quiet again until 200.
        $episodes = 0;
        $beatAt = 100;

        // The feed carries far more distinct ids than a single whereIn can bind, so
        // the membership lookup runs a chunk at a time.
        foreach (array_chunk($seriesIds, 1000) as $chunk) {
            $query = Show::query()
                ->select(['id', '_tvdb_id', '_tvdb_defaultSeasonType'])
                ->whereIn('_tvdb_id', $chunk)
                ->whereNotNull('episodes_synced_at');

            // PK pagination because the walk writes to the rows it iterates: the seed
            // stamps episodes_synced_at, which offset pagination would skip or
            // double-process. Report-and-continue so one bad show can't abort the run.
            $query->chunkById(200, function (Collection $shows) use ($seed, &$failures, &$episodes, &$beatAt): void {
                foreach ($shows as $show) {
                    try {
                        $episodes += $seed->handle($show);
                    } catch (TvdbRequestFailed|TvdbAuthenticationFailed $e) {
                        report($e);
                        $failures++;
                    }

                    if ($episodes >= $beatAt) {
                        $this->mark(self::HEARTBEAT_TAG, $episodes);
                        $beatAt = intdiv($episodes, 100) * 100 + 100;
                    }
                }
            });
        }

        return ['episodes' => $episodes, 'failures' => $failures];
    }
}
