<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Console\Commands;

use App\Domains\Catalog\Actions\ReconcileImdbOnlyShows;
use App\Domains\Catalog\Actions\UpsertTmdbImages;
use App\Domains\Catalog\Actions\UpsertTmdbShows;
use App\Domains\Catalog\Enums\SyncFeed;
use App\Domains\Catalog\Models\Show;
use App\Domains\Catalog\Services\TmdbApiService;
use App\Domains\Catalog\Support\SyncMarker;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

#[Description('Two-phase TMDB show sync: hydrate our own shows by their resolvable id (direct _tmdb_id, or reconciled from _imdb_id via /find), then update-changed from the marker-derived changes window')]
#[Signature('catalog:sync-shows-tmdb {--fresh} {--limit=}')]
class SyncTmdbShows extends Command
{
    /**
     * Hydrate and upsert candidate shows in chunks of this size.
     */
    private const int BATCH_SIZE = 1000;

    /**
     * Running count of hydrated shows, for the every-1000th progress heartbeat.
     */
    private int $processed = 0;

    public function handle(
        TmdbApiService $api,
        ReconcileImdbOnlyShows $reconcileImdbOnly,
        UpsertTmdbShows $upsertShows,
        UpsertTmdbImages $upsertImages,
        SyncMarker $marker,
    ): int {
        // Capture the run-start before hydrating so the marker advances to when this
        // run began, not when it finished — updates landing mid-run are then
        // re-covered by the next run's overlap window.
        $startedAt = CarbonImmutable::now();

        // Plain writeln progress, not spin()/progress(): those fork a renderer
        // that overwrites the terminal (and render nothing under catalog:sync's
        // nested Artisan::call), which swallowed the per-batch heartbeat below.
        $this->output->writeln('Hydrating TMDB shows…');
        $insertFailed = $this->hydrateOwnShows($api, $reconcileImdbOnly, $upsertShows, $upsertImages);

        // The changes pass only makes sense for a full default run: --fresh already
        // re-hydrates every candidate above (a changes pass would be redundant),
        // and --limit is a bounded partial run that a full changes sweep would blow
        // past.
        $changesFailed = false;

        if (! $this->option('fresh') && $this->option('limit') === null) {
            $changesFailed = $this->updateChanged($marker, $api, $upsertShows, $upsertImages);
        }

        // Advance only on a clean, unbounded run: a per-id or changes-feed failure
        // means this run didn't fully cover its window, and --limit is a partial run,
        // so the marker must not move past the span still owed to the next run.
        if (! $insertFailed && ! $changesFailed && $this->option('limit') === null) {
            $marker->advance(SyncFeed::TmdbShows, $startedAt);
        }

        return self::SUCCESS;
    }

    /**
     * Insert phase: hydrate OUR OWN shows that carry a resolvable id — a row
     * already holding `_tmdb_id` hydrates directly; an imdb-only row reconciles
     * its tmdb id through /find first. Skips already-synced rows unless `--fresh`
     * reprocesses everything, and caps the candidate set at `--limit`.
     *
     * Returns true if any chunk failed, so the caller can leave the marker untouched.
     */
    private function hydrateOwnShows(
        TmdbApiService $api,
        ReconcileImdbOnlyShows $reconcileImdbOnly,
        UpsertTmdbShows $upsertShows,
        UpsertTmdbImages $upsertImages,
    ): bool {
        // chunkById, not get()->chunk(): a --fresh run targets the whole ~173k-row
        // TVDB show universe, so materializing the candidate set is prohibitive. And
        // chunkById, not chunk()/lazy(): the loop WRITES to the rows it iterates (the
        // imdb-only reconcile stamps _tmdb_id, hydration stamps tmdb_synced_at — a
        // WHERE-filtered column on a default run), and only PK-paginated chunking is
        // immune to skipping/double-processing rows whose filtered columns mutate
        // mid-iteration.
        $query = Show::query()
            ->where(function ($query): void {
                $query->whereNotNull('_tmdb_id')->orWhereNotNull('_imdb_id');
            })
            ->unless($this->option('fresh'), function ($query): void {
                $query->whereNull('tmdb_synced_at');
            })
            ->select(['id', '_tmdb_id', '_imdb_id']);

        // chunkById can't compose with a SQL LIMIT (each page re-queries by PK), so
        // enforce --limit by counting down candidate rows across chunks and stopping
        // once the cap is reached.
        $limit = $this->option('limit');
        $remaining = $limit === null ? null : (int) $limit;

        $failed = false;

        $query->chunkById(self::BATCH_SIZE, function (Collection $chunk) use (
            &$remaining,
            &$failed,
            $api,
            $reconcileImdbOnly,
            $upsertShows,
            $upsertImages,
        ): bool {
            if ($remaining !== null) {
                $chunk = $chunk->take($remaining);
                $remaining -= $chunk->count();
            }

            $failed = $this->hydrateChunkSafely($chunk, $api, $reconcileImdbOnly, $upsertShows, $upsertImages) || $failed;

            return $remaining === null || $remaining > 0;
        });

        return $failed;
    }

    /**
     * Update-changed phase: refresh locally held shows that TMDB reports as
     * changed within the marker-derived window, hydrating the intersection of the
     * changes feed and our synced rows through the shared insert-phase plumbing.
     *
     * Returns true if the changes-feed fetch failed or any re-hydrate chunk failed,
     * so the caller can leave the marker where it is and re-cover this window later.
     */
    private function updateChanged(
        SyncMarker $marker,
        TmdbApiService $api,
        UpsertTmdbShows $upsertShows,
        UpsertTmdbImages $upsertImages,
    ): bool {
        $this->output->writeln('Updating changed shows…');

        $window = $marker->window(SyncFeed::TmdbShows);

        // Report rather than propagate a changes-feed failure so a transient error
        // paging the feed — or resolving which of those ids we hold — can't abort
        // the whole command; the insert phase already ran, so we exit SUCCESS with
        // what we have.
        try {
            $changedIds = $api->changedTvIds($window->startDate(), $window->endDate());

            // Only refresh ids we already hold — a changed id we've never synced is
            // an insert candidate the hydrate phase owns, not an update. Chunk the
            // unbounded changes feed before the whereIn: a busy window returns
            // thousands of ids, and every other intersection in this domain
            // pre-chunks to BATCH_SIZE to stay under packet/bind-param limits.
            $ids = [];

            foreach (array_chunk($changedIds, self::BATCH_SIZE) as $chunk) {
                $ids = array_merge($ids, Show::query()
                    ->whereNotNull('tmdb_synced_at')
                    ->whereIn('_tmdb_id', $chunk)
                    ->pluck('_tmdb_id')
                    ->all());
            }
        } catch (\Throwable $e) {
            report($e);

            return true;
        }

        $failed = false;

        foreach (array_chunk($ids, self::BATCH_SIZE) as $chunk) {
            $failed = $this->syncChunkSafely($chunk, $api, $upsertShows, $upsertImages) || $failed;
        }

        return $failed;
    }

    /**
     * Run one candidate chunk, reporting rather than propagating a failure so one
     * bad batch (a transient API failure or a single malformed row) can't abort
     * the entire ingest and silently truncate the catalog — the loop moves on.
     *
     * Returns true if the chunk failed (a per-id pool miss, or a thrown error), so
     * the failure gates the run's marker advance.
     *
     * @param  Collection<int, Show>  $shows
     */
    private function hydrateChunkSafely(
        Collection $shows,
        TmdbApiService $api,
        ReconcileImdbOnlyShows $reconcileImdbOnly,
        UpsertTmdbShows $upsertShows,
        UpsertTmdbImages $upsertImages,
    ): bool {
        try {
            return $this->hydrateChunk($shows, $api, $reconcileImdbOnly, $upsertShows, $upsertImages);
        } catch (\Throwable $e) {
            report($e);

            return true;
        }
    }

    /**
     * Hydrate one candidate chunk: hydrate direct `_tmdb_id` rows as-is, reconcile
     * imdb-only rows through /find (stamping the resolved id before hydrating), and
     * hydrate the combined tmdb id set.
     *
     * Returns true if the hydrate fetch failed. An unresolved imdb-only row (a /find
     * miss) is NOT a failure — it stays tmdb_synced_at-null and is retried every run
     * regardless of the marker; only a per-id hydrate failure gates the advance.
     *
     * @param  Collection<int, Show>  $shows
     */
    private function hydrateChunk(
        Collection $shows,
        TmdbApiService $api,
        ReconcileImdbOnlyShows $reconcileImdbOnly,
        UpsertTmdbShows $upsertShows,
        UpsertTmdbImages $upsertImages,
    ): bool {
        $directIds = $shows->whereNotNull('_tmdb_id')->pluck('_tmdb_id')->all();

        $resolvedIds = $reconcileImdbOnly->handle($shows, $api);

        $ids = array_values(array_unique(array_merge($directIds, $resolvedIds)));

        return $ids === [] ? false : $this->syncChunk($ids, $api, $upsertShows, $upsertImages);
    }

    /**
     * Run one chunk, reporting rather than propagating a failure so one bad batch
     * (a transient API failure or a single malformed row) can't abort the entire
     * ingest and silently truncate the catalog — the loop moves on to the next.
     *
     * Returns true if the chunk failed (a per-id pool miss, or a thrown error), so
     * the failure gates the run's marker advance.
     *
     * @param  array<int, int>  $ids
     */
    private function syncChunkSafely(
        array $ids,
        TmdbApiService $api,
        UpsertTmdbShows $upsertShows,
        UpsertTmdbImages $upsertImages,
    ): bool {
        try {
            return $this->syncChunk($ids, $api, $upsertShows, $upsertImages);
        } catch (\Throwable $e) {
            report($e);

            return true;
        }
    }

    /**
     * Hydrate one chunk of tmdb ids, upsert the non-404 shows, then persist
     * each hydrated payload's images against its freshly upserted show row.
     *
     * Returns true if a per-id fetch failed. The pool reports-not-throws a non-404
     * per-id failure and drops that id from the keyed result, so a missing key — a
     * short count against the requested ids — is the only signal it happened. A 404
     * stays present-as-null, so it never counts as a failure.
     *
     * @param  array<int, int>  $ids
     */
    private function syncChunk(
        array $ids,
        TmdbApiService $api,
        UpsertTmdbShows $upsertShows,
        UpsertTmdbImages $upsertImages,
    ): bool {
        $results = $api->tvShows($ids);

        $failed = count($results) < count(array_unique($ids));

        $payloads = array_values(array_filter($results));

        if ($payloads === []) {
            return $failed;
        }

        $upsertShows->handle($payloads);

        // Heartbeat: print every 1000th hydrated title. spin()/progress() render
        // nothing under catalog:sync's nested Artisan::call, so this plain line
        // is the only visible movement; the label distinguishes this phase.
        foreach ($payloads as $payload) {
            if (++$this->processed % 1000 === 0) {
                $this->output->writeln("  [tmdb shows {$this->processed}] ".($payload['name'] ?? '—'));
            }
        }

        $shows = Show::query()
            ->whereIn('_tmdb_id', array_column($payloads, 'id'))
            ->get()
            ->keyBy('_tmdb_id');

        foreach ($payloads as $payload) {
            if (! isset($payload['images'])) {
                continue;
            }

            $show = $shows->get($payload['id']);

            if ($show instanceof Show) {
                $upsertImages->handle($show, $payload['images']);
            }
        }

        return $failed;
    }
}
