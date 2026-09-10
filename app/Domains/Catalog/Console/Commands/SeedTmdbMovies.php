<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Console\Commands;

use App\Domains\Catalog\Actions\ReindexTouchedRows;
use App\Domains\Catalog\Actions\UpsertTmdbImages;
use App\Domains\Catalog\Actions\UpsertTmdbMovies;
use App\Domains\Catalog\Services\TmdbApiService;
use App\Domains\Catalog\Services\TmdbExportService;
use App\Domains\Catalog\Support\Batches;
use App\Domains\Catalog\Support\SyncMarker;
use Generator;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;

/**
 * The operator's remedy for a movies marker stale past the window cap, where the
 * incremental changes feed can no longer cover the gap: one full pass over the ids
 * export, hydrating everything the catalog does not already hold, then the ordinary
 * changes pass over the marker window — the half that refreshes what the catalog
 * DOES hold, and the only reason a plain seed has earned the right to move the
 * marker. Under --fresh the export pass refreshes held titles too, so it carries
 * that right on its own; only --fresh can clear a capped marker.
 *
 * Deliberately on no schedule. A blind weekly sweep would re-pay ~62k
 * unpersistable hydrations every run and, worse, keep a stalled marker looking
 * healthy — which is exactly how the marker-stall bug behind FLIX-289 stayed
 * invisible.
 */
#[Description('Full-catalog TMDB movie seed from the ids export: hydrate every exported id the catalog does not hold (operator-invoked; never scheduled)')]
#[Signature('catalog:seed-movies {--fresh}')]
final class SeedTmdbMovies extends TmdbMoviesCommand
{
    private const string EXPORT = 'movie_ids';

    /** Export rows read before a `[scan n]` beat — the export runs to ~1M rows. */
    private const int SCAN_BEAT = 10_000;

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

        // Both phases run, because runLeg() advances the marker on any
        // zero-failure run: the export scan can only reach ids the catalog does NOT
        // hold, so an insert-only seed would jump the marker to now while every UPDATE
        // inside the span it skipped stays unfetched — and silence recordCappedWindow()
        // in the bargain. The changes pass is what earns the advance.
        //
        // Except under --fresh, which re-hydrates EVERY exported id: the span the
        // capped window leaves uncovered is genuinely covered by that full pass, so the
        // changes pass would be redundant work AND — because it reports a capped window
        // as a failure — would hold the marker back forever, on the one run that
        // actually repaired the gap. `catalog:seed-movies --fresh` is therefore the
        // operator's remedy for a marker stale past the cap; a plain seed leaves held
        // titles inside that span unfetched, so its alarm rightly persists.
        //
        // Changes first, then the export scan, so the two phases are disjoint by
        // construction rather than by de-dup bookkeeping: this leg leaves
        // insertHeartbeatTag() at its null default, so updateChanged() refreshes HELD
        // ids only. Running it first means it probes the pre-run held set and the scan
        // then covers exactly the remainder. Scan-first instead, an id that is both
        // unheld and named in the window gets stamped tmdb_synced_at by the scan, reads
        // as held to the changes pass moments later, and is hydrated — and counted under
        // the same heartbeat tag — a second time, so the closing total stops being the
        // true persisted count.
        // Assigned before the `||` so a failing phase can't short-circuit the other away.
        return $this->runLeg($marker, function () use ($export, $marker): bool {
            $changesFailed = $this->option('fresh') ? false : $this->updateChanged($marker);
            $insertFailed = $this->insertNew($export);

            return $insertFailed || $changesFailed;
        });
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
