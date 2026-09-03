<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Console\Commands;

use App\Domains\Catalog\Actions\ReindexTouchedRows;
use App\Domains\Catalog\Actions\UpsertTmdbImages;
use App\Domains\Catalog\Actions\UpsertTmdbMovies;
use App\Domains\Catalog\Data\SyncWindow;
use App\Domains\Catalog\Enums\SyncFeed;
use App\Domains\Catalog\Models\Movie;
use App\Domains\Catalog\Services\TmdbApiService;
use App\Domains\Catalog\Services\TmdbExportService;
use App\Domains\Catalog\Support\Batches;
use App\Domains\Catalog\Support\SyncMarker;
use Generator;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Database\Eloquent\Builder;

/**
 * The operator's remedy for a movies marker stale past the window cap, where the
 * incremental changes feed can no longer cover the gap: one full pass over the ids
 * export, hydrating everything the catalog does not already hold.
 *
 * Deliberately on no schedule. A blind weekly sweep would re-pay ~62k
 * unpersistable hydrations every run and, worse, keep a stalled marker looking
 * healthy — which is exactly how the marker-stall bug behind FLIX-289 stayed
 * invisible.
 */
#[Description('Full-catalog TMDB movie seed from the ids export: hydrate every exported id the catalog does not hold (operator-invoked; never scheduled)')]
#[Signature('catalog:seed-movies {--fresh}')]
class SeedTmdbMovies extends TmdbSyncCommand
{
    private const string EXPORT = 'movie_ids';

    /** Export rows read before a `[scan n]` beat — the export runs to ~1M rows. */
    private const int SCAN_BEAT = 10_000;

    private UpsertTmdbMovies $upsertMovies;

    public function handle(
        TmdbExportService $export,
        TmdbApiService $api,
        UpsertTmdbMovies $upsertMovies,
        UpsertTmdbImages $upsertImages,
        SyncMarker $marker,
        ReindexTouchedRows $reindexTouchedRows,
    ): int {
        $this->api = $api;
        $this->upsertMovies = $upsertMovies;
        $this->upsertImages = $upsertImages;
        $this->reindexTouchedRows = $reindexTouchedRows;

        return $this->runLeg($marker, fn (): bool => $this->insertNew($export));
    }

    /**
     * Insert phase: hydrate every exported id we don't already hold.
     */
    private function insertNew(TmdbExportService $export): bool
    {
        $file = $this->timedPhase(
            'Downloading movie-ids export…',
            fn (): string => $export->download(self::EXPORT),
        );

        try {
            return $this->timedPhase(
                'Syncing movies…',
                fn (): bool => $this->syncRows($export, $file),
            );
        } finally {
            @unlink($file);
        }
    }

    protected function feed(): SyncFeed
    {
        return SyncFeed::TmdbMovies;
    }

    /**
     * @return Builder<Movie>
     */
    protected function query(): Builder
    {
        return Movie::query();
    }

    protected function entityLabel(): string
    {
        return 'movies';
    }

    protected function heartbeatTag(): string
    {
        return 'tmdb movies';
    }

    /**
     * @return iterable<int, int>
     */
    protected function changedIds(SyncWindow $window): iterable
    {
        return $this->api->changedMovieIds($window->startDate(), $window->endDate());
    }

    /**
     * @param  array<int, int>  $ids
     * @return array<int, array<string, mixed>|null>
     */
    protected function hydrate(array $ids): array
    {
        return $this->api->movies($ids);
    }

    /**
     * @param  list<array<string, mixed>>  $payloads
     */
    protected function upsertPayloads(array $payloads): void
    {
        $this->upsertMovies->handle($payloads);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function payloadTitle(array $payload): ?string
    {
        return $payload['title'] ?? null;
    }

    private function syncRows(TmdbExportService $export, string $file): bool
    {
        $failed = false;

        foreach (Batches::of($this->keptRows($export, $file), self::HYDRATE_SIZE) as $rows) {
            $ids = array_map(static fn (array $row): int => (int) $row['id'], $rows);

            $failed = $this->syncChunkSafely($ids) || $failed;
        }

        return $failed;
    }

    /**
     * The exported rows not already synced (all of them under `--fresh`).
     *
     * Yields row by row rather than returning a set, so a batch hydrates before the
     * next buffer is probed — the interleave is what keeps the resident set bounded.
     *
     * The scan beat counts rows READ, upstream of the already-synced filter: on a
     * seeded catalog every row is filtered out, and a beat downstream of that would
     * be as silent as the upsert beat it exists to cover for.
     *
     * @return Generator<int, array{id: int|string}>
     */
    private function keptRows(TmdbExportService $export, string $file): Generator
    {
        if ($this->option('fresh')) {
            foreach ($export->rows($file) as $row) {
                $this->beatEvery('scan', self::SCAN_BEAT);

                yield $row;
            }

            return;
        }

        foreach (Batches::of($export->rows($file), self::PROBE_SIZE) as $buffer) {
            $this->beatEvery('scan', self::SCAN_BEAT, count($buffer));

            yield from $this->unsyncedRows($buffer);
        }
    }

    /**
     * @param  array<int, array{id: int|string}>  $buffer
     * @return Generator<int, array{id: int|string}>
     */
    private function unsyncedRows(array $buffer): Generator
    {
        $syncedIds = $this->syncedIdsAmong(
            collect($buffer)->map(static fn (array $row): int => (int) $row['id'])
        )->flip();

        foreach ($buffer as $row) {
            if (! $syncedIds->has((int) $row['id'])) {
                yield $row;
            }
        }
    }
}
