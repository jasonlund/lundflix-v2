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
     * Lower than the ratings sync's 5000 because the bulk CASE update binds 15
     * placeholders per row here — id + value for each of the 7 basics columns,
     * plus the id again in the WHERE IN — against ratings' 5. That caps a safe
     * batch at ~4300 rows before MySQL's 65,535 placeholder limit.
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

        // The whole catalog's ids up front: title.basics is millions of rows,
        // so a membership query per row is not an option.
        $ids = $this->catalogIds->all();
        $size = $this->batchSize();

        try {
            /** @var array<string, array<string, mixed>> $batch */
            $batch = [];

            foreach ($this->datasets->rows($path, ImdbDataset::TitleBasics) as $row) {
                if (! isset($ids[$row['tconst']])) {
                    continue;
                }

                if ($row['isAdult'] === true) {
                    $this->adultSkipped++;

                    continue;
                }

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
     * Persist the accumulated basics buffer, emit a progress heartbeat, and reset it.
     *
     * @param  array<string, array<string, mixed>>  $batch
     */
    private function flush(array &$batch): void
    {
        if ($batch === []) {
            return;
        }

        $this->importer->handle($batch);
        $this->processed += count($batch);
        $this->output->writeln("  [imdb titles {$this->processed}]");
        $batch = [];
    }
}
