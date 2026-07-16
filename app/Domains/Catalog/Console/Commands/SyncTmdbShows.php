<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Console\Commands;

use App\Domains\Catalog\Actions\UpsertTmdbImages;
use App\Domains\Catalog\Actions\UpsertTmdbShows;
use App\Domains\Catalog\Exceptions\TmdbShowCrosswalkCollision;
use App\Domains\Catalog\Models\Show;
use App\Domains\Catalog\Services\TmdbApiService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

#[Description('Two-phase TMDB show sync: hydrate our own shows by their resolvable id (direct _tmdb_id, or reconciled from _imdb_id via /find), then update-changed from the rolling changes feed')]
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
        UpsertTmdbShows $upsertShows,
        UpsertTmdbImages $upsertImages,
    ): int {
        // Plain writeln progress, not spin()/progress(): those fork a renderer
        // that overwrites the terminal (and render nothing under catalog:sync's
        // nested Artisan::call), which swallowed the per-batch heartbeat below.
        $this->output->writeln('Hydrating TMDB shows…');
        $this->hydrateOwnShows($api, $upsertShows, $upsertImages);

        // The changes pass only makes sense for a full default run: --fresh already
        // re-hydrates every candidate above (a changes pass would be redundant),
        // and --limit is a bounded partial run that a full changes sweep would blow
        // past.
        if (! $this->option('fresh') && $this->option('limit') === null) {
            $this->updateChanged($api, $upsertShows, $upsertImages);
        }

        return self::SUCCESS;
    }

    /**
     * Insert phase: hydrate OUR OWN shows that carry a resolvable id — a row
     * already holding `_tmdb_id` hydrates directly; an imdb-only row reconciles
     * its tmdb id through /find first. Skips already-synced rows unless `--fresh`
     * reprocesses everything, and caps the candidate set at `--limit`.
     */
    private function hydrateOwnShows(
        TmdbApiService $api,
        UpsertTmdbShows $upsertShows,
        UpsertTmdbImages $upsertImages,
    ): void {
        $query = Show::query()
            ->where(function ($query): void {
                $query->whereNotNull('_tmdb_id')->orWhereNotNull('_imdb_id');
            })
            ->when(! $this->option('fresh'), function ($query): void {
                $query->whereNull('tmdb_synced_at');
            })
            ->orderBy('id');

        $limit = $this->option('limit');

        if ($limit !== null) {
            $query->limit((int) $limit);
        }

        $candidates = $query->get(['id', '_tmdb_id', '_imdb_id']);

        foreach ($candidates->chunk(self::BATCH_SIZE) as $chunk) {
            $this->hydrateChunkSafely($chunk, $api, $upsertShows, $upsertImages);
        }
    }

    /**
     * Update-changed phase: refresh locally held shows that TMDB reports as
     * changed within the rolling 14-day window, hydrating the intersection of the
     * changes feed and our synced rows through the shared insert-phase plumbing.
     */
    private function updateChanged(
        TmdbApiService $api,
        UpsertTmdbShows $upsertShows,
        UpsertTmdbImages $upsertImages,
    ): void {
        $this->output->writeln('Updating changed shows…');

        $end = now()->utc()->format('Y-m-d');
        $start = now()->utc()->subDays(14)->format('Y-m-d');

        // Report rather than propagate a changes-feed failure so a transient error
        // paging the feed — or resolving which of those ids we hold — can't abort
        // the whole command; the insert phase already ran, so we exit SUCCESS with
        // what we have.
        try {
            $changedIds = $api->changedTvIds($start, $end);

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

            return;
        }

        foreach (array_chunk($ids, self::BATCH_SIZE) as $chunk) {
            $this->syncChunkSafely($chunk, $api, $upsertShows, $upsertImages);
        }
    }

    /**
     * Run one candidate chunk, reporting rather than propagating a failure so one
     * bad batch (a transient API failure or a single malformed row) can't abort
     * the entire ingest and silently truncate the catalog — the loop moves on.
     *
     * @param  Collection<int, Show>  $shows
     */
    private function hydrateChunkSafely(
        Collection $shows,
        TmdbApiService $api,
        UpsertTmdbShows $upsertShows,
        UpsertTmdbImages $upsertImages,
    ): void {
        try {
            $this->hydrateChunk($shows, $api, $upsertShows, $upsertImages);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * Hydrate one candidate chunk: hydrate direct `_tmdb_id` rows as-is, reconcile
     * imdb-only rows through /find (stamping the resolved id before hydrating), and
     * hydrate the combined tmdb id set.
     *
     * @param  Collection<int, Show>  $shows
     */
    private function hydrateChunk(
        Collection $shows,
        TmdbApiService $api,
        UpsertTmdbShows $upsertShows,
        UpsertTmdbImages $upsertImages,
    ): void {
        $directIds = $shows->whereNotNull('_tmdb_id')->pluck('_tmdb_id')->all();

        $resolvedIds = $this->reconcileImdbOnly($shows, $api);

        $ids = array_values(array_unique(array_merge($directIds, $resolvedIds)));

        if ($ids === []) {
            return;
        }

        $this->syncChunk($ids, $api, $upsertShows, $upsertImages);
    }

    /**
     * Resolve tmdb ids for the chunk's imdb-only rows through /find, stamping each
     * resolved id onto its row. An imdb id whose result has no tv_results stays
     * TVDB-only — no stamp, no hydrate.
     *
     * @param  Collection<int, Show>  $shows
     * @return array<int, int>
     */
    private function reconcileImdbOnly(Collection $shows, TmdbApiService $api): array
    {
        $imdbIds = $shows->whereNull('_tmdb_id')
            ->pluck('_imdb_id')
            ->filter()
            ->values()
            ->all();

        if ($imdbIds === []) {
            return [];
        }

        $resolvedIds = [];

        foreach ($api->findManyByImdbId($imdbIds) as $imdbId => $result) {
            $tvResults = $result['tv_results'] ?? [];

            if ($tvResults === []) {
                continue;
            }

            $tmdbId = (int) $tvResults[0]['id'];

            // The UNIQUE `_tmdb_id` guard: a resolved id already claimed by another
            // row can't be re-pointed onto this one. Skip + report and leave the row
            // TVDB-only (same accepted outcome as an empty tv_results) rather than
            // let the constraint violation abort the whole chunk.
            if (Show::query()->where('_tmdb_id', $tmdbId)->exists()) {
                report(TmdbShowCrosswalkCollision::forResolvedId($imdbId, $tmdbId));

                continue;
            }

            Show::query()
                ->where('_imdb_id', $imdbId)
                ->whereNull('_tmdb_id')
                ->update(['_tmdb_id' => $tmdbId]);

            $resolvedIds[] = $tmdbId;
        }

        return $resolvedIds;
    }

    /**
     * Run one chunk, reporting rather than propagating a failure so one bad batch
     * (a transient API failure or a single malformed row) can't abort the entire
     * ingest and silently truncate the catalog — the loop moves on to the next.
     *
     * @param  array<int, int>  $ids
     */
    private function syncChunkSafely(
        array $ids,
        TmdbApiService $api,
        UpsertTmdbShows $upsertShows,
        UpsertTmdbImages $upsertImages,
    ): void {
        try {
            $this->syncChunk($ids, $api, $upsertShows, $upsertImages);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * Hydrate one chunk of tmdb ids, upsert the non-404 shows, then persist
     * each hydrated payload's images against its freshly upserted show row.
     *
     * @param  array<int, int>  $ids
     */
    private function syncChunk(
        array $ids,
        TmdbApiService $api,
        UpsertTmdbShows $upsertShows,
        UpsertTmdbImages $upsertImages,
    ): void {
        $payloads = array_values(array_filter($api->tvShows($ids)));

        if ($payloads === []) {
            return;
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
    }
}
