<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Console\Commands;

use App\Domains\Catalog\Console\Commands\Concerns\MeasuresElapsedTime;
use App\Domains\Catalog\Console\Commands\Concerns\SkipsUnchangedDataset;
use App\Domains\Catalog\Enums\ImdbDataset;
use App\Domains\Catalog\Services\ImdbDatasetService;
use App\Domains\Catalog\Support\CatalogImdbIds;
use App\Domains\Common\Console\Concerns\EmitsHeartbeat;
use Illuminate\Console\Command;
use Illuminate\Support\LazyCollection;

/**
 * The gate → download → stream → mark run shared by the IMDb dataset syncs, plus
 * the buffered write batching that fans a pre-matched row stream into bulk
 * writes. Each concrete command owns only how a dataset row becomes a buffer
 * entry, and hands this base the per-feed seams below.
 *
 * The buffer's value type is left to the subclass (one row per id for titles and
 * ratings, a whole group per id for akas): every frame here keys off the ids alone.
 *
 * Progress is plain writeln lines, never a progress bar: bars render nothing
 * under catalog:sync's nested Artisan::call, so the legs' heartbeats are the
 * only visible movement.
 */
abstract class ImdbSyncCommand extends Command
{
    use EmitsHeartbeat;
    use MeasuresElapsedTime;
    use SkipsUnchangedDataset;

    /**
     * The largest --batch any feed accepts, and the one bound an operator can
     * actually breach: the batch sizes both the pre-filter's id probe and the
     * write buffer, and the widest write (titles, 7 columns) spends 15 bindings a
     * row plus the update's WHERE IN id against MySQL's 65,535 placeholder cap —
     * about 4369 rows. Rounded down for headroom, since a global scope or a new
     * column spends bindings the arithmetic above does not see. A feed writing
     * fewer columns has room to raise it by overriding {@see maxBatchSize()}.
     */
    private const int MAX_BATCH_SIZE = 4000;

    /** The latest cumulative total the heartbeat reported, kept so the close-out can flush it. */
    private int $scannedRows = 0;

    public function __construct(
        protected readonly ImdbDatasetService $datasets,
        protected readonly CatalogImdbIds $catalogIds,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        if (! $this->shouldSyncDataset($this->dataset())) {
            return self::SUCCESS;
        }

        $path = $this->datasets->download($this->dataset());

        $this->output->writeln("Importing IMDb {$this->feed()}…");

        $startedAt = microtime(true);

        try {
            $this->stream($path);
        } finally {
            @unlink($path);
        }

        $this->reportSummary();

        // Silent on a normal run: the stream's tail probe batch already marked
        // this exact total, so the dedupe swallows it. It earns its place for the
        // dataset that yields no data rows at all — the only path where nothing
        // has beaten yet, and a leg that printed no count would read as a hang.
        $this->flushTotal($this->heartbeatTag(), $this->scannedRows);

        $this->reportElapsed($startedAt);

        // Deliberately past the try/finally, not inside it: a download or import
        // that throws must leave the old marker standing so the next run retries
        // this dataset instead of treating it as already applied.
        $this->advanceDatasetMarker($this->dataset());

        $this->output->writeln('Done.');

        return self::SUCCESS;
    }

    /** The dataset this leg downloads, gates on, and marks once applied. */
    abstract protected function dataset(): ImdbDataset;

    /**
     * The feed's one-word name, as it appears in the phase line and every
     * heartbeat — `ratings`, `titles`, `akas`. Not derivable from the dataset:
     * `title.basics` is spoken of as titles.
     */
    abstract protected function feed(): string;

    /** Flush size when --batch is absent; each feed sizes its own buffer. */
    abstract protected function defaultBatchSize(): int;

    /**
     * Stream the dataset's catalog-matched rows into batched writes.
     *
     * Must consume `matchedRows()` to the end — it holds the download's gz
     * handle open until the generator completes.
     */
    abstract protected function stream(string $path): void;

    /**
     * Hand a narrowed batch to this feed's importer.
     *
     * @param  array<string, mixed>  $rows
     */
    abstract protected function import(array $rows): void;

    /**
     * This feed's catalog-matched rows, heartbeat wired in.
     *
     * The batch size bounds the pre-filter's id probe as well as the write
     * buffer, so both sides of the stream move in the same steps.
     *
     * @return LazyCollection<int, array<string, mixed>>
     */
    protected function matchedRows(string $path, int $batchSize): LazyCollection
    {
        return $this->datasets->matchedRows(
            $path,
            $this->dataset(),
            fn (array $ids): array => $this->catalogIds->existing($ids),
            $batchSize,
            $this->heartbeat(...),
        );
    }

    /**
     * The ceiling a supplied --batch is reduced to; overridable per feed.
     */
    protected function maxBatchSize(): int
    {
        return self::MAX_BATCH_SIZE;
    }

    protected function batchSize(): int
    {
        $batch = (int) $this->option('batch');

        if ($batch <= 0) {
            return $this->defaultBatchSize();
        }

        $max = $this->maxBatchSize();

        if ($batch > $max) {
            $this->output->writeln("Reducing --batch {$batch} to the {$this->feed()} ceiling of {$max}.");

            return $max;
        }

        return $batch;
    }

    /**
     * The entries of a batch this feed actually writes. Defaults to all of them;
     * a feed that withholds some overrides.
     *
     * @param  array<string, mixed>  $rows
     * @return array<string, mixed>
     */
    protected function writable(array $rows): array
    {
        return $rows;
    }

    /**
     * A closing line about what the run withheld, printed before the elapsed
     * line. Silent unless a feed has something to account for.
     */
    protected function reportSummary(): void {}

    /**
     * Persist the writable share of the buffer and reset it.
     *
     * The entries arrive already catalog-matched — the membership probe runs
     * upstream, in the streaming pre-filter — so this is purely write batching.
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

        $rows = $this->writable($rows);

        if ($rows === []) {
            return;
        }

        $this->import($rows);
    }

    /**
     * Report scanned dataset rows, not applied ones: the datasets cover far more
     * titles than the catalog does, so a stretch that matches nothing still has
     * to show movement.
     *
     * mark(), not beat(): the count arrives once per probe batch at whatever
     * ragged figure the batch ended on, and the batch size is already the
     * operator's cadence knob — gating those on a fixed interval would drop
     * nearly every line.
     */
    private function heartbeat(int $scannedRows): void
    {
        $this->scannedRows = $scannedRows;

        $this->mark($this->heartbeatTag(), $scannedRows);
    }

    /**
     * Heartbeat tag, source-prefixed (`imdb ratings`) so a line names which leg
     * emitted it. Derived from the feed rather than a per-command constant, and
     * shared by both callsites deliberately: the beat and the closing flushTotal
     * must name the identical tag or the dedupe misses and the leg closes on a
     * duplicate line.
     */
    private function heartbeatTag(): string
    {
        return "imdb {$this->feed()}";
    }

    /**
     * Close a leg with the wall time its streaming import took.
     *
     * @param  float  $startedAt  the `microtime(true)` reading taken before the import
     */
    private function reportElapsed(float $startedAt): void
    {
        $this->output->writeln("  [elapsed {$this->preciseSecondsSince($startedAt)}s]");
    }
}
