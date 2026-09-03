<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Console\Commands;

use App\Domains\Catalog\Actions\ReindexTouchedRows;
use App\Domains\Catalog\Actions\UpsertTmdbImages;
use App\Domains\Catalog\Console\Commands\Concerns\MeasuresElapsedTime;
use App\Domains\Catalog\Data\SyncWindow;
use App\Domains\Catalog\Enums\SyncFeed;
use App\Domains\Catalog\Models\Movie;
use App\Domains\Catalog\Models\Show;
use App\Domains\Catalog\Services\TmdbApiService;
use App\Domains\Catalog\Support\Batches;
use App\Domains\Catalog\Support\SyncMarker;
use App\Domains\Common\Console\Concerns\EmitsHeartbeat;
use Carbon\CarbonImmutable;
use Closure;
use DateTimeInterface;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The frame every TMDB leg runs inside: the leg pipeline (watermark → ingest →
 * marker), the deferred-indexing policy, the heartbeat counters and the phase
 * timing. Each concrete command owns which ingest phases it runs and hands this
 * base the per-feed seams below.
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

    /**
     * The span a CAP_DAYS floor cut off the front of this run's window, as
     * `{start} to {end}`, or null when the floor never fired.
     *
     * Held for the closing summary rather than printed where it is detected: the
     * run still covers what it can reach, so the gap is a fact about the whole
     * run, not an event partway through it.
     */
    private ?string $uncoveredSpan = null;

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
     * The heartbeat tag for a changed id we do NOT already hold — an insert candidate
     * the feed just discovered. Null means the leg refreshes only: an unheld id is left
     * to whatever other phase owns discovery.
     *
     * Opt-in rather than default, because a feed-driven insert is only safe where TMDB
     * owns the row's identity. `shows` rows are created solely from TVDB, so an unheld
     * /tv/changes id must never reach the table (see Catalog/GUIDELINES.md).
     */
    protected function insertHeartbeatTag(): ?string
    {
        return null;
    }

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
     * than treated as a failure.
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
     * The leg owns its WHOLE ingest body, including whether it reads the changes
     * feed at all — a seed leg rescans its source and has no window to refresh.
     *
     * @param  Closure(): bool  $ingest  every ingest phase the leg runs; true if any of it failed
     */
    protected function runLeg(SyncMarker $marker, Closure $ingest): int
    {
        // Run-start, not run-end: updates landing mid-run stay inside the next run's
        // overlap window rather than falling in the gap.
        $startedAt = CarbonImmutable::now();

        $this->withoutIndexing(function () use ($marker, $ingest, $startedAt): void {
            // A failure means the window wasn't fully covered — the marker must not move
            // past a span still owed to the next run.
            if (! $ingest()) {
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

        // Only a leg that can insert closes an insert total; a refresh-only leg would
        // otherwise report a `0` for volume it never had any way to produce.
        $insertTag = $this->insertHeartbeatTag();

        if ($insertTag !== null) {
            $this->flushTotal($insertTag, $this->counted[$insertTag] ?? 0);
        }

        $this->failureSummary($this->failedEntities, $this->entityLabel(), 'marker not advanced');
        $this->failureSummary(
            $this->failedChangesWindows,
            'changes-feed window',
            // The span only exists for a capped window; a feed that simply failed to
            // read has no gap to name, so it keeps the bare consequence.
            $this->uncoveredSpan === null
                ? 'marker not advanced'
                : "{$this->uncoveredSpan} uncovered; marker not advanced",
        );

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
     * Hydrate what TMDB reports changed in the marker window: the ids we already
     * hold, and — where the leg opts in — the ones we don't.
     *
     * The two used to be separate phases fed by separate sources, which is the only
     * reason they were ever apart: they ask the same question from opposite sides of
     * syncedIdsAmong(). Once the feed became the sole source for both, keeping them
     * split meant probing the same slice twice.
     *
     * Reading, resolving and hydrating share one pass, so a mid-stream throw leaves
     * earlier slices already hydrated. Accepted: the marker doesn't advance, so the
     * next run re-covers the whole window over idempotent upserts.
     */
    protected function updateChanged(SyncMarker $marker): bool
    {
        $insertTag = $this->insertHeartbeatTag();

        // A leg that only refreshes is still honestly "updating"; one that also
        // discovers rows from the feed is not.
        $label = $insertTag === null
            ? "Updating changed {$this->entityLabel()}…"
            : "Syncing changed {$this->entityLabel()}…";

        return $this->timedPhase($label, function () use ($marker, $insertTag): bool {
            $window = $marker->window($this->feed());

            // A floored window is a real, permanent gap: the span between the marker
            // and the floor is never fetched and never retried. The run still covers
            // what it can reach — it just must not pass for a clean one, or the
            // marker advances over the gap and the loss becomes unrecoverable. This
            // is what let a stalled marker sit unnoticed on production for months.
            $failed = $this->recordCappedWindow($window);

            // The whole loop sits inside the try, not just the call: the feed is a
            // generator, so it defers its first request to the first iteration.
            try {
                // The feed is unbounded, so probe per slice; one whereIn over a busy
                // window risks the placeholder limit.
                foreach (Batches::of($this->changedIds($window), self::PROBE_SIZE) as $slice) {
                    $this->beatEvery('probe', self::PROBE_BEAT, count($slice));

                    $held = $this->syncedIdsAmong($slice);

                    // Both halves accumulate into $failed on their own line: neither
                    // side may short-circuit the other away.
                    $refreshFailed = $this->syncInBatches($held->all());

                    // Two tags inside one pass, because an operator reads insert volume
                    // and refresh volume as different facts about a run.
                    $insertFailed = $insertTag === null
                        ? false
                        : $this->syncInBatches(collect($slice)->diff($held)->values()->all(), $insertTag);

                    $failed = $refreshFailed || $insertFailed || $failed;
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
     * Note a window the CAP_DAYS floor truncated, returning whether it did.
     *
     * Counted on $failedChangesWindows rather than a counter of its own: that
     * counter exists for a window-level fault with no entity to count, and an
     * unfetchable span is exactly that. It carries the exit code for free.
     */
    private function recordCappedWindow(SyncWindow $window): bool
    {
        if (! $window->isCapped()) {
            return false;
        }

        $this->uncoveredSpan = "{$window->uncoveredStartDate()} to {$window->startDate()}";
        $this->failedChangesWindows++;

        return true;
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

        try {
            $result = $work();
        } catch (\Throwable $e) {
            // Without this the start line is the last thing printed and the phase's
            // missing closing line is the only symptom a leg died at all.
            $this->output->writeln("{$label} failed after {$this->secondsSince($startedAt)}s");

            throw $e;
        }

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
     * @param  string|null  $tag  the beat tag for these ids; null takes heartbeatTag()
     */
    protected function syncInBatches(array $ids, ?string $tag = null): bool
    {
        $failed = false;

        foreach (array_chunk($ids, self::HYDRATE_SIZE) as $batch) {
            $failed = $this->syncChunkSafely($batch, $tag) || $failed;
        }

        return $failed;
    }

    /**
     * A throw carries no per-id detail, so the whole chunk is owed — unlike a
     * short result set, where the pool names which ids came back.
     *
     * @param  array<int, int>  $ids
     */
    protected function syncChunkSafely(array $ids, ?string $tag = null): bool
    {
        try {
            return $this->syncChunk($ids, $tag);
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
    protected function syncChunk(array $ids, ?string $tag = null): bool
    {
        $tag ??= $this->heartbeatTag();

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
            $this->beatEvery($tag, self::HYDRATE_BEAT, suffix: $this->payloadTitle($payload) ?? '—');
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
