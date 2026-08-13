<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Console\Commands;

use App\Domains\Catalog\Actions\ImportImdbTitles;
use App\Domains\Catalog\Enums\ImdbDataset;
use App\Domains\Catalog\Services\ImdbDatasetService;
use App\Domains\Catalog\Support\CatalogImdbIds;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

#[Description('Sync IMDb titles: re-download the title.basics dataset and refresh the basics columns on every matching catalog title')]
#[Signature('catalog:sync-titles {--batch=}')]
class SyncImdbTitles extends Command
{
    /**
     * Default flush size for the accumulated basics buffer; --batch overrides it.
     *
     * Bounds both the raw dataset rows held in memory and the ids each flush's
     * catalog-membership probe binds into its `in (…)` — not the bulk CASE update,
     * which only ever writes the catalog's share of a batch.
     */
    private const int BATCH_SIZE = 2000;

    /**
     * Running count of titles applied, for the per-flush progress heartbeat.
     */
    private int $processed = 0;

    /**
     * Adult rows matched in the catalog but deliberately never written.
     */
    private int $adultSkipped = 0;

    public function __construct(
        private readonly ImdbDatasetService $datasets,
        private readonly CatalogImdbIds $catalogIds,
        private readonly ImportImdbTitles $importer,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $path = $this->datasets->download(ImdbDataset::TitleBasics);

        // Plain writeln progress, not a progress bar: bars render nothing
        // under catalog:sync's nested Artisan::call, so a per-flush heartbeat
        // is the only visible movement.
        $this->output->writeln('Importing IMDb titles…');

        $size = $this->batchSize();

        try {
            /** @var array<string, array<string, mixed>> $batch */
            $batch = [];

            foreach ($this->datasets->rows($path, ImdbDataset::TitleBasics) as $row) {
                $batch[$row['tconst']] = $row;

                if (count($batch) >= $size) {
                    $this->flush($batch);
                }
            }

            $this->flush($batch);
        } finally {
            @unlink($path);
        }

        $this->output->writeln("Skipped {$this->adultSkipped} adult ".Str::plural('title', $this->adultSkipped).'.');

        return self::SUCCESS;
    }

    private function batchSize(): int
    {
        $batch = (int) $this->option('batch');

        return $batch > 0 ? $batch : self::BATCH_SIZE;
    }

    /**
     * Narrow the buffered dataset rows to the writable ones, persist them, and
     * reset the buffer.
     *
     * The catalog-membership probe lives here rather than in the streaming loop so
     * the run never holds the whole catalog's ids in memory, and the adult drop
     * follows it so that tally stays catalog-scoped. A batch left with nothing to
     * write stays silent: a real run flushes thousands of zero-match batches, and a
     * heartbeat for each would bury the signal.
     *
     * @param  array<string, array<string, mixed>>  $batch
     */
    private function flush(array &$batch): void
    {
        $rows = $batch;
        $batch = [];

        if ($rows === []) {
            return;
        }

        $rows = $this->dropAdultRows(
            array_intersect_key($rows, $this->catalogIds->existing(array_keys($rows)))
        );

        if ($rows === []) {
            return;
        }

        $this->importer->handle($rows);
        $this->processed += count($rows);
        $this->output->writeln("  [imdb titles {$this->processed}]");
    }

    /**
     * Drop the adult rows, adding them to the run's skip tally.
     *
     * @param  array<string, array<string, mixed>>  $rows
     * @return array<string, array<string, mixed>>
     */
    private function dropAdultRows(array $rows): array
    {
        $writable = collect($rows)
            ->reject(fn (array $row): bool => $row['isAdult'] === true)
            ->all();

        $this->adultSkipped += count($rows) - count($writable);

        return $writable;
    }
}
