<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Console\Commands;

use App\Domains\Catalog\Services\ImdbDatasetService;
use App\Domains\Catalog\Support\CatalogImdbIds;
use Illuminate\Console\Command;

/**
 * The buffered, catalog-narrowed flush both IMDb dataset syncs that only touch
 * titles we already hold share. Each concrete command owns its own streaming
 * loop — how a dataset row becomes a buffer entry — and hands this base the
 * per-feed seams below. The ratings sync writes its whole buffer unprobed, so it
 * is deliberately not one of these.
 *
 * The buffer's value type is left to the subclass (one row per id for titles, a
 * whole group per id for akas): every frame here keys off the ids alone.
 */
abstract class ImdbSyncCommand extends Command
{
    /**
     * Running count of titles applied, for the per-flush progress heartbeat.
     */
    private int $processed = 0;

    public function __construct(
        protected readonly ImdbDatasetService $datasets,
        protected readonly CatalogImdbIds $catalogIds,
    ) {
        parent::__construct();
    }

    /** Flush size when --batch is absent; each feed sizes its own buffer. */
    abstract protected function defaultBatchSize(): int;

    /** Heartbeat tag, e.g. "imdb titles". */
    abstract protected function heartbeatTag(): string;

    /**
     * Hand a narrowed batch to this feed's importer.
     *
     * @param  array<string, mixed>  $rows
     */
    abstract protected function import(array $rows): void;

    protected function batchSize(): int
    {
        $batch = (int) $this->option('batch');

        return $batch > 0 ? $batch : $this->defaultBatchSize();
    }

    /**
     * The entries of a catalog-matched batch this feed actually writes. Defaults
     * to all of them; a feed that withholds some overrides.
     *
     * @param  array<string, mixed>  $rows
     * @return array<string, mixed>
     */
    protected function writable(array $rows): array
    {
        return $rows;
    }

    /**
     * Narrow the buffered entries to the writable ones, persist them, and reset
     * the buffer.
     *
     * The catalog-membership probe lives here rather than in the streaming loop so
     * the run never holds the whole catalog's ids in memory. A batch left with
     * nothing to write stays silent: the datasets cover far more titles than the
     * catalog does, so a real run flushes thousands of zero-match batches and a
     * heartbeat for each would bury the signal.
     *
     * @param  array<string, mixed>  $batch  taken by reference and emptied — the caller keeps filling the same array
     */
    protected function flush(array &$batch): void
    {
        $rows = $batch;
        $batch = [];

        if ($rows === []) {
            return;
        }

        $rows = $this->writable(
            array_intersect_key($rows, $this->catalogIds->existing(array_keys($rows)))
        );

        if ($rows === []) {
            return;
        }

        $this->import($rows);
        $this->processed += count($rows);
        $this->output->writeln("  [{$this->heartbeatTag()} {$this->processed}]");
    }
}
