<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Console\Commands;

use App\Domains\Catalog\Actions\UpsertTmdbImages;
use App\Domains\Catalog\Actions\UpsertTmdbMovies;
use App\Domains\Catalog\Models\Movie;
use App\Domains\Catalog\Services\TmdbApiService;
use App\Domains\Catalog\Services\TmdbExportService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\LazyCollection;

#[Description('Two-phase TMDB movie sync: insert-new from the ids export, then update-changed from the rolling changes feed')]
#[Signature('catalog:sync-movies {--fresh} {--limit=}')]
class SyncTmdbMovies extends Command
{
    /**
     * Hydrate and upsert exported ids in chunks of this size.
     */
    private const int BATCH_SIZE = 1000;

    /**
     * TMDB daily export name for movies.
     */
    private const string EXPORT = 'movie_ids';

    /**
     * Running count of hydrated movies, for the every-1000th progress heartbeat.
     */
    private int $processed = 0;

    public function handle(
        TmdbExportService $export,
        TmdbApiService $api,
        UpsertTmdbMovies $upsertMovies,
        UpsertTmdbImages $upsertImages,
    ): int {
        // Plain writeln progress, not spin()/progress(): those fork a renderer
        // that overwrites the terminal (and render nothing under catalog:sync's
        // nested Artisan::call), which swallowed the per-batch heartbeat below.
        $this->output->writeln('Downloading movie-ids export…');
        $file = $export->download(self::EXPORT);

        try {
            $this->output->writeln('Syncing movies…');
            $this->syncRows($export, $file, $api, $upsertMovies, $upsertImages);
        } finally {
            @unlink($file);
        }

        // The changes pass only makes sense for a full default run: --fresh already
        // re-hydrates every exported id above (a changes pass would be redundant),
        // and --limit is a bounded partial run that a full changes sweep would blow
        // past.
        if (! $this->option('fresh') && $this->option('limit') === null) {
            $this->updateChanged($api, $upsertMovies, $upsertImages);
        }

        return self::SUCCESS;
    }

    /**
     * Update-changed phase: refresh locally held movies that TMDB reports as
     * changed within the rolling 14-day window, hydrating the intersection of the
     * changes feed and our synced rows through the shared insert-phase plumbing.
     */
    private function updateChanged(
        TmdbApiService $api,
        UpsertTmdbMovies $upsertMovies,
        UpsertTmdbImages $upsertImages,
    ): void {
        $this->output->writeln('Updating changed movies…');

        $end = now()->utc()->format('Y-m-d');
        $start = now()->utc()->subDays(14)->format('Y-m-d');

        // Report rather than propagate a changes-feed or id-resolution failure so a
        // transient error — paging the feed or querying our rows — can't abort the
        // whole command; the insert phase already ran, so we exit SUCCESS with what
        // we have.
        try {
            $changedIds = $api->changedMovieIds($start, $end);

            // Only refresh ids we already hold — a changed id we've never synced is an
            // insert candidate the export phase owns, not an update. The changes feed
            // is unbounded, so resolve the intersection in BATCH_SIZE slices: a single
            // whereIn over a busy window risks the packet/placeholder limit.
            $ids = [];

            foreach (array_chunk($changedIds, self::BATCH_SIZE) as $chunk) {
                $resolved = Movie::query()
                    ->whereNotNull('tmdb_synced_at')
                    ->whereIn('_tmdb_id', $chunk)
                    ->pluck('_tmdb_id')
                    ->all();

                $ids = array_merge($ids, $resolved);
            }
        } catch (\Throwable $e) {
            report($e);

            return;
        }

        foreach (array_chunk($ids, self::BATCH_SIZE) as $chunk) {
            $this->syncChunkSafely($chunk, $api, $upsertMovies, $upsertImages);
        }
    }

    /**
     * Stream the kept rows, hydrating and upserting in BATCH_SIZE chunks.
     */
    private function syncRows(
        TmdbExportService $export,
        string $file,
        TmdbApiService $api,
        UpsertTmdbMovies $upsertMovies,
        UpsertTmdbImages $upsertImages,
    ): void {
        $ids = [];

        foreach ($this->keptRows($export, $file) as $row) {
            $ids[] = (int) $row['id'];

            if (count($ids) >= self::BATCH_SIZE) {
                $this->syncChunkSafely($ids, $api, $upsertMovies, $upsertImages);
                $ids = [];
            }
        }

        if ($ids !== []) {
            $this->syncChunkSafely($ids, $api, $upsertMovies, $upsertImages);
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
        UpsertTmdbMovies $upsertMovies,
        UpsertTmdbImages $upsertImages,
    ): void {
        try {
            $this->syncChunk($ids, $api, $upsertMovies, $upsertImages);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * Stream the exported rows to process: skip movies already synced (unless
     * `--fresh` reprocesses everything) and cap the result at `--limit`.
     *
     * @return LazyCollection<int, array{id: int|string}>
     */
    private function keptRows(TmdbExportService $export, string $file): LazyCollection
    {
        $skip = $this->option('fresh')
            ? []
            : array_flip(Movie::query()->whereNotNull('tmdb_synced_at')->pluck('_tmdb_id')->filter()->all());

        $rows = $export->rows($file)
            ->reject(fn (array $row): bool => isset($skip[(int) $row['id']]));

        $limit = $this->option('limit');

        return $limit === null ? $rows : $rows->take((int) $limit);
    }

    /**
     * Hydrate one chunk of exported ids, upsert the non-404 movies, then persist
     * each hydrated payload's images against its freshly upserted movie row.
     *
     * @param  array<int, int>  $ids
     */
    private function syncChunk(
        array $ids,
        TmdbApiService $api,
        UpsertTmdbMovies $upsertMovies,
        UpsertTmdbImages $upsertImages,
    ): void {
        // Drop 404 (null) payloads, and video:true entries — a video:true TMDB
        // record is a promo/trailer, not a real film, so it never gets ingested.
        $payloads = array_values(array_filter(
            $api->movies($ids),
            static fn (?array $payload): bool => $payload !== null && empty($payload['video']),
        ));

        if ($payloads === []) {
            return;
        }

        $upsertMovies->handle($payloads);

        // Heartbeat: print every 1000th hydrated title. spin()/progress() render
        // nothing under catalog:sync's nested Artisan::call, so this plain line
        // is the only visible movement; the label distinguishes this phase.
        foreach ($payloads as $payload) {
            if (++$this->processed % 1000 === 0) {
                $this->output->writeln("  [movies {$this->processed}] ".($payload['title'] ?? '—'));
            }
        }

        $movies = Movie::query()
            ->whereIn('_tmdb_id', array_column($payloads, 'id'))
            ->get()
            ->keyBy('_tmdb_id');

        foreach ($payloads as $payload) {
            if (! isset($payload['images'])) {
                continue;
            }

            $movie = $movies->get($payload['id']);

            if ($movie instanceof Movie) {
                $upsertImages->handle($movie, $payload['images']);
            }
        }
    }
}
