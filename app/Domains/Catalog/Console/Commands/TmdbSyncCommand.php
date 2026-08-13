<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Console\Commands;

use App\Domains\Catalog\Actions\UpsertTmdbImages;
use App\Domains\Catalog\Enums\SyncFeed;
use App\Domains\Catalog\Models\Movie;
use App\Domains\Catalog\Models\Show;
use App\Domains\Catalog\Services\TmdbApiService;
use App\Domains\Catalog\Support\SyncMarker;
use App\Domains\Catalog\Support\SyncWindow;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The update-changed half both TMDB syncs share. Each concrete command owns its
 * own insert phase (an ids export for movies, our own rows for shows) and hands
 * this base the per-feed seams below.
 */
abstract class TmdbSyncCommand extends Command
{
    /** Decoded payloads held live per batch — see "probe wide, hydrate narrow" in GUIDELINES.md. */
    protected const int HYDRATE_SIZE = 250;

    /** Bare ids buffered before a membership probe — insert candidates on insert, changed ids on update. */
    protected const int PROBE_SIZE = 1000;

    /**
     * Set from handle()'s method injection before any pipeline call, so the
     * shared frames read them off the command instead of threading them down.
     */
    protected TmdbApiService $api;

    protected UpsertTmdbImages $upsertImages;

    private int $processed = 0;

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

    /** Heartbeat tag — TMDB shows disambiguate against the TVDB show sync's own. */
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
     * Refresh titles we already hold that TMDB reports changed in the marker window.
     *
     * Reading, resolving and hydrating share one pass, so a mid-stream throw leaves
     * earlier slices already hydrated. Accepted: the marker doesn't advance, so the
     * next run re-covers the whole window over idempotent upserts.
     */
    protected function updateChanged(SyncMarker $marker): bool
    {
        $this->output->writeln("Updating changed {$this->entityLabel()}…");

        $window = $marker->window($this->feed());

        $failed = false;

        // The whole loop sits inside the try, not just the call: the feed is a
        // generator, so it defers its first request to the first iteration.
        try {
            // Only ids we already hold — a changed id never synced is an insert
            // candidate the insert phase owns. The feed is unbounded, so probe per
            // slice; one whereIn over a busy window risks the placeholder limit.
            $buffer = [];

            foreach ($this->changedIds($window) as $id) {
                $buffer[] = $id;

                if (count($buffer) >= self::PROBE_SIZE) {
                    $failed = $this->syncInBatches($this->syncedIdsAmong($buffer)->all()) || $failed;
                    $buffer = [];
                }
            }

            if ($buffer !== []) {
                $failed = $this->syncInBatches($this->syncedIdsAmong($buffer)->all()) || $failed;
            }
        } catch (\Throwable $e) {
            report($e);

            return true;
        }

        return $failed;
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
     * @param  array<int, int>  $ids
     */
    protected function syncChunkSafely(array $ids): bool
    {
        try {
            return $this->syncChunk($ids);
        } catch (\Throwable $e) {
            report($e);

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
     * GUIDELINES.md). A 404 stays present-as-null, so it never counts as one.
     *
     * @param  array<int, int>  $ids
     */
    protected function syncChunk(array $ids): bool
    {
        $results = $this->hydrate($ids);

        $failed = count($results) < count(array_unique($ids));

        $payloads = $this->payloads($results);

        if ($payloads === []) {
            return $failed;
        }

        $this->upsertPayloads($payloads);

        foreach ($payloads as $payload) {
            if (++$this->processed % 1000 === 0) {
                $this->output->writeln("  [{$this->heartbeatTag()} {$this->processed}] ".($this->payloadTitle($payload) ?? '—'));
            }
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
