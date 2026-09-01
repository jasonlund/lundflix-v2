<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Console\Commands;

use App\Domains\Catalog\Actions\ReindexTouchedRows;
use App\Domains\Catalog\Actions\UpsertTmdbImages;
use App\Domains\Catalog\Console\Commands\Concerns\MeasuresElapsedTime;
use App\Domains\Catalog\Enums\SyncFeed;
use App\Domains\Catalog\Models\Movie;
use App\Domains\Catalog\Models\Show;
use App\Domains\Catalog\Services\TmdbApiService;
use App\Domains\Catalog\Support\Batches;
use App\Domains\Catalog\Support\SyncMarker;
use App\Domains\Catalog\Support\SyncWindow;
use App\Domains\Common\Console\Concerns\EmitsHeartbeat;
use Carbon\CarbonImmutable;
use Closure;
use DateTimeInterface;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The frame both TMDB syncs run inside: the leg pipeline (watermark → insert →
 * update-changed → marker), the deferred-indexing policy, the heartbeat counters
 * and the phase timing. Each concrete command owns only its insert phase (an ids
 * export for movies, our own rows for shows) and hands this base the per-feed
 * seams below.
 */
abstract class TmdbSyncCommand extends Command
{
    use EmitsHeartbeat;
    use MeasuresElapsedTime;

    /** Decoded payloads held live per batch — see "probe wide, hydrate narrow" in GUIDELINES.md. */
    protected const int HYDRATE_SIZE = 250;

    /** Bare ids buffered before a membership probe — insert candidates on insert, changed ids on update. */
    protected const int PROBE_SIZE = 1000;

    /** Changes-feed ids probed for local membership before a `[probe n]` beat. */
    private const int PROBE_BEAT = 1000;

    /** Titles upserted before a `[{tag} n] {title}` beat. */
    private const int HYDRATE_BEAT = 1000;

    /**
     * Set from handle()'s method injection before any pipeline call, so the
     * shared frames read them off the command instead of threading them down.
     */
    protected TmdbApiService $api;

    protected UpsertTmdbImages $upsertImages;

    protected ReindexTouchedRows $reindexTouchedRows;

    /**
     * Units counted per beat tag, so a phase's counter survives across the calls
     * that feed it. Distinct from the trait's $heartbeatMarks, which holds the last
     * boundary each tag PRINTED: runLeg() closes a tag on its exact final figure,
     * which a rounded mark can't supply.
     *
     * @var array<string, int>
     */
    private array $counted = [];

    /**
     * Entities the run owed at the end — a hydrate throw, or ids the pool dropped.
     *
     * Counted alongside the booleans the phases return rather than in place of
     * them: the booleans gate the marker and cover failures no counter sees (a
     * leg's own catch, e.g. SyncTmdbShows::hydrateChunkSafely, which names no
     * entity), so the two are not interchangeable.
     */
    private int $failedEntities = 0;

    /** Changes-feed windows the run never finished reading. */
    private int $failedChangesWindows = 0;

    /** The marker feed whose window this command reads and advances. */
    abstract protected function feed(): SyncFeed;

    /**
     * The table this feed hydrates. Both models it can be are accepted by
     * UpsertTmdbImages.
     *
     * @return Builder<Movie>|Builder<Show>
     */
    abstract protected function query(): Builder;

    /** Names the entity in the phase line, e.g. "Updating changed movies…". */
    abstract protected function entityLabel(): string;

    /** Heartbeat tag, source-prefixed (`tmdb movies`) so a line names which sync emitted it. */
    abstract protected function heartbeatTag(): string;

    /**
     * The changes feed for the window. A generator, so nothing is requested
     * until updateChanged() iterates it.
     *
     * @return iterable<int, int>
     */
    abstract protected function changedIds(SyncWindow $window): iterable;

    /**
     * @param  array<int, int>  $ids
     * @return array<int, array<string, mixed>|null>
     */
    abstract protected function hydrate(array $ids): array;

    /**
     * @param  list<array<string, mixed>>  $payloads
     */
    abstract protected function upsertPayloads(array $payloads): void;

    /**
     * @param  array<string, mixed>  $payload
     */
    abstract protected function payloadTitle(array $payload): ?string;

    /**
     * The results worth upserting. A null is a 404 miss, dropped here rather
     * than treated as a failure; a feed with promo-only records narrows further.
     *
     * @param  array<int, array<string, mixed>|null>  $results
     * @return list<array<string, mixed>>
     */
    protected function payloads(array $results): array
    {
        return array_values(array_filter($results));
    }

    /**
     * The leg's model, read off the query() seam so a leg still names its table
     * exactly once.
     */
    protected function model(): Movie|Show
    {
        return $this->query()->getModel();
    }

    /**
     * Run a leg end to end: stamp its watermark, insert with indexing deferred,
     * refresh what the changes window reports, advance the marker if the run owed
     * nothing, index every row the leg touched, then close the run.
     *
     * The frame lives here rather than in each handle() so that whatever phases a
     * leg adds, its every upsert lands inside the indexing wrap and the single
     * deferred pass lands outside it — the one ordering that must not be got wrong.
     *
     * Reads the `--fresh` switch, so every leg's signature must declare it.
     *
     * @param  Closure(): bool  $insertNew  the leg's own insert phase; true if any of it failed
     */
    protected function runLeg(SyncMarker $marker, Closure $insertNew): int
    {
        // Run-start, not run-end: updates landing mid-run stay inside the next run's
        // overlap window rather than falling in the gap.
        $startedAt = CarbonImmutable::now();

        $this->withoutIndexing(function () use ($marker, $insertNew, $startedAt): void {
            $insertFailed = $insertNew();

            // --fresh already re-hydrated every candidate, so a changes pass is redundant.
            $changesFailed = $this->option('fresh') ? false : $this->updateChanged($marker);

            // A failure means the window wasn't fully covered — the marker must not move
            // past a span still owed to the next run.
            if (! $insertFailed && ! $changesFailed) {
                $marker->advance($this->feed(), $startedAt);
            }
        });

        $this->reindexTouched($startedAt);

        return $this->closeRun();
    }

    /**
     * Close the run: the tag's exact final total, then whatever the run still
     * owed, then the exit code.
     *
     * Reported at the end rather than raised at the failure site, because the
     * rows a failing run did write are committed and indexed either way — the
     * non-zero exit is what tells a scheduler the window was only part covered.
     */
    private function closeRun(): int
    {
        $tag = $this->heartbeatTag();

        $this->flushTotal($tag, $this->counted[$tag] ?? 0);

        $this->failureSummary($this->failedEntities, $this->entityLabel(), 'marker not advanced');
        $this->failureSummary($this->failedChangesWindows, 'changes-feed window', 'marker not advanced');

        $this->output->writeln('Done.');

        return $this->failedEntities > 0 || $this->failedChangesWindows > 0
            ? self::FAILURE
            : self::SUCCESS;
    }

    /**
     * Run the leg's ingest phases with Scout's save-time sync switched off.
     *
     * A leg writes the same row from several phases, so indexing per save costs an
     * engine round trip per write; reindexTouched() replaces them with one pass.
     * It must be called OUTSIDE this wrap, or the deferred pass indexes nothing.
     *
     * @param  Closure(): void  $ingest
     */
    private function withoutIndexing(Closure $ingest): void
    {
        $this->model()::withoutSyncingToSearch($ingest);
    }

    /**
     * Index every row the leg touched, in one pass, at the end of the leg.
     *
     * Runs whatever a phase reported: rows written before a failure are on disk
     * either way, and leaving them unindexed would hide them until a later run
     * happened to touch them again.
     */
    private function reindexTouched(DateTimeInterface $watermark): void
    {
        // Under SCOUT_QUEUE the phase only dispatches the index writes, so timedPhase's
        // elapsed seconds time the dispatch — say "queued" rather than claim it indexed.
        $label = $this->reindexTouchedRows->queuesIndexing()
            ? "Queueing {$this->entityLabel()} for reindex…"
            : "Reindexing {$this->entityLabel()}…";

        $this->timedPhase($label, function () use ($watermark): void {
            $this->reindexTouchedRows->handle(
                $this->model()::class,
                $watermark,
                fn (string $line) => $this->output->writeln($line),
            );
        });
    }

    /**
     * Refresh titles we already hold that TMDB reports changed in the marker window.
     *
     * Reading, resolving and hydrating share one pass, so a mid-stream throw leaves
     * earlier slices already hydrated. Accepted: the marker doesn't advance, so the
     * next run re-covers the whole window over idempotent upserts.
     */
    private function updateChanged(SyncMarker $marker): bool
    {
        return $this->timedPhase("Updating changed {$this->entityLabel()}…", function () use ($marker): bool {
            $window = $marker->window($this->feed());

            $failed = false;

            // The whole loop sits inside the try, not just the call: the feed is a
            // generator, so it defers its first request to the first iteration.
            try {
                // Only ids we already hold — a changed id never synced is an insert
                // candidate the insert phase owns. The feed is unbounded, so probe per
                // slice; one whereIn over a busy window risks the placeholder limit.
                foreach (Batches::of($this->changedIds($window), self::PROBE_SIZE) as $slice) {
                    $this->beatEvery('probe', self::PROBE_BEAT, count($slice));

                    $failed = $this->syncInBatches($this->syncedIdsAmong($slice)->all()) || $failed;
                }
            } catch (\Throwable $e) {
                report($e);

                $this->failedChangesWindows++;

                return true;
            }

            return $failed;
        });
    }

    /**
     * Announce a phase, run it, then close it with the seconds it took.
     *
     * The closing line is what tells an operator a phase ENDED rather than hung —
     * a start line alone reads the same either way.
     *
     * @template TPhase
     *
     * @param  Closure(): TPhase  $work
     * @return TPhase
     */
    protected function timedPhase(string $label, Closure $work): mixed
    {
        $this->output->writeln($label);

        $startedAt = CarbonImmutable::now();

        $result = $work();

        $this->output->writeln("{$label} done in {$this->secondsSince($startedAt)}s");

        return $result;
    }

    /**
     * Count $units of work under $tag, then beat every $cadence boundary the new
     * running total crossed.
     *
     * The single entry point for the counter: work *scanned* is counted here as
     * well as work written, because on a seeded catalog nothing is upserted, so the
     * upsert beat never fires and a scan or probe beat is the only thing proving
     * the run is alive.
     */
    protected function beatEvery(string $tag, int $cadence, int $units = 1, ?string $suffix = null): void
    {
        $this->counted[$tag] = ($this->counted[$tag] ?? 0) + $units;

        $this->beat($tag, $this->counted[$tag], $cadence, $suffix);
    }

    /**
     * @param  array<int, int>  $ids  a whole probe slice, re-cut to HYDRATE_SIZE
     */
    protected function syncInBatches(array $ids): bool
    {
        $failed = false;

        foreach (array_chunk($ids, self::HYDRATE_SIZE) as $batch) {
            $failed = $this->syncChunkSafely($batch) || $failed;
        }

        return $failed;
    }

    /**
     * A throw carries no per-id detail, so the whole chunk is owed — unlike a
     * short result set, where the pool names which ids came back.
     *
     * @param  array<int, int>  $ids
     */
    protected function syncChunkSafely(array $ids): bool
    {
        try {
            return $this->syncChunk($ids);
        } catch (\Throwable $e) {
            report($e);

            $this->failedEntities += count($ids);

            return true;
        }
    }

    /**
     * The insert phase's skip set and the update phase's working set — the same
     * question from opposite sides. Both columns are indexed, which is what makes
     * probing per slice cheaper than reading the synced catalog once.
     *
     * @param  array<int, int>|Collection<int, int>  $ids
     * @return Collection<int, int>
     */
    protected function syncedIdsAmong(array|Collection $ids): Collection
    {
        return $this->query()
            ->whereNotNull('tmdb_synced_at')
            ->whereIn('_tmdb_id', $ids)
            ->pluck('_tmdb_id');
    }

    /**
     * A short result count is the only signal of a per-id fetch failure (see
     * GUIDELINES.md), and the shortfall is how many entities the run owes. A 404
     * stays present-as-null, so it never counts as one.
     *
     * Measured against the DISTINCT ids: the pool de-duplicates and keys by id,
     * so a repeated id comes back once and would otherwise read as a loss. The
     * clamp holds that floor for any hydrate() that returns more than it was
     * asked for, which no current leg does.
     *
     * @param  array<int, int>  $ids
     */
    protected function syncChunk(array $ids): bool
    {
        $results = $this->hydrate($ids);

        $missing = max(count(array_unique($ids)) - count($results), 0);

        $failed = $missing > 0;

        $this->failedEntities += $missing;

        $payloads = $this->payloads($results);

        if ($payloads === []) {
            return $failed;
        }

        $this->upsertPayloads($payloads);

        foreach ($payloads as $payload) {
            $this->beatEvery($this->heartbeatTag(), self::HYDRATE_BEAT, suffix: $this->payloadTitle($payload) ?? '—');
        }

        $models = $this->query()
            ->whereIn('_tmdb_id', array_column($payloads, 'id'))
            // UpsertTmdbImages only reaches ->media(); a full hydrate would carry
            // $attributes AND $original for every row in the chunk.
            ->select(['id', '_tmdb_id'])
            ->get()
            ->keyBy('_tmdb_id');

        foreach ($payloads as $payload) {
            if (! isset($payload['images'])) {
                continue;
            }

            $model = $models->get($payload['id']);

            if ($model instanceof Movie || $model instanceof Show) {
                $this->upsertImages->handle($model, $payload['images']);
            }
        }

        return $failed;
    }
}
