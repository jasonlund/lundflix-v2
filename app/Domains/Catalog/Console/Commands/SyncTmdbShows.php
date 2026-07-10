<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Console\Commands;

use App\Domains\Catalog\Actions\UpsertTmdbImages;
use App\Domains\Catalog\Actions\UpsertTmdbShows;
use App\Domains\Catalog\Enums\TmdbExport;
use App\Domains\Catalog\Models\Show;
use App\Domains\Catalog\Services\TmdbApiService;
use App\Domains\Catalog\Services\TmdbExportService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\LazyCollection;

#[Description('Two-phase TMDB show sync: insert-new from the tv-series-ids export (create-or-merge onto any matching source id), then update-changed from the rolling changes feed')]
#[Signature('tmdb:sync-shows {--fresh} {--limit=}')]
class SyncTmdbShows extends Command
{
    /**
     * Hydrate and upsert exported ids in chunks of this size.
     */
    private const int BATCH_SIZE = 1000;

    /**
     * Running count of hydrated shows, for the every-1000th progress heartbeat.
     */
    private int $processed = 0;

    public function handle(
        TmdbExportService $export,
        TmdbApiService $api,
        UpsertTmdbShows $upsertShows,
        UpsertTmdbImages $upsertImages,
    ): int {
        // Plain writeln progress, not spin()/progress(): those fork a renderer
        // that overwrites the terminal (and render nothing under sync:catalog's
        // nested Artisan::call), which swallowed the per-batch heartbeat below.
        $this->output->writeln('Downloading tv-series-ids export…');
        $file = $export->download(TmdbExport::TvSeriesIds);

        try {
            $this->output->writeln('Syncing TMDB shows…');
            $this->syncRows($export, $file, $api, $upsertShows, $upsertImages);
        } finally {
            @unlink($file);
        }

        // The changes pass only makes sense for a full default run: --fresh already
        // re-hydrates every exported id above (a changes pass would be redundant),
        // and --limit is a bounded partial run that a full changes sweep would blow
        // past.
        if (! $this->option('fresh') && $this->option('limit') === null) {
            $this->updateChanged($api, $upsertShows, $upsertImages);
        }

        return self::SUCCESS;
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
        // paging the feed can't abort the whole command — the insert phase already
        // ran, so we exit SUCCESS with what we have.
        try {
            $changedIds = $api->changedTvIds($start, $end);
        } catch (\Throwable $e) {
            report($e);

            return;
        }

        // Only refresh ids we already hold — a changed id we've never synced is an
        // insert candidate the export phase owns, not an update.
        $ids = Show::query()
            ->whereNotNull('tmdb_synced_at')
            ->whereIn('_tmdb_id', $changedIds)
            ->pluck('_tmdb_id')
            ->all();

        foreach (array_chunk($ids, self::BATCH_SIZE) as $chunk) {
            $this->syncChunkSafely($chunk, $api, $upsertShows, $upsertImages);
        }
    }

    /**
     * Stream the kept rows, hydrating and upserting in BATCH_SIZE chunks.
     */
    private function syncRows(
        TmdbExportService $export,
        string $file,
        TmdbApiService $api,
        UpsertTmdbShows $upsertShows,
        UpsertTmdbImages $upsertImages,
    ): void {
        $ids = [];

        foreach ($this->keptRows($export, $file) as $row) {
            // Skip a malformed (non-numeric) export id rather than let (int) coerce
            // it to 0 and waste a /tv/0 hydration call.
            if (! is_numeric($row['id'])) {
                continue;
            }

            $ids[] = (int) $row['id'];

            if (count($ids) >= self::BATCH_SIZE) {
                $this->syncChunkSafely($ids, $api, $upsertShows, $upsertImages);
                $ids = [];
            }
        }

        if ($ids !== []) {
            $this->syncChunkSafely($ids, $api, $upsertShows, $upsertImages);
        }
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
     * Stream the exported rows to process: skip shows already synced (unless
     * `--fresh` reprocesses everything) and cap the result at `--limit`.
     *
     * @return LazyCollection<int, array{id: int|string}>
     */
    private function keptRows(TmdbExportService $export, string $file): LazyCollection
    {
        $skip = $this->option('fresh')
            ? []
            : array_flip(Show::query()->whereNotNull('tmdb_synced_at')->pluck('_tmdb_id')->filter()->all());

        $rows = $export->rows($file)
            ->reject(fn (array $row): bool => isset($skip[(int) $row['id']]));

        $limit = $this->option('limit');

        return $limit === null ? $rows : $rows->take((int) $limit);
    }

    /**
     * Hydrate one chunk of exported ids, upsert the non-404 shows, then persist
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
        // nothing under sync:catalog's nested Artisan::call, so this plain line
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
