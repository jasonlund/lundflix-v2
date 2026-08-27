<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Console\Commands;

use App\Domains\Catalog\Actions\ImportImdbTitles;
use App\Domains\Catalog\Enums\ImdbDataset;
use App\Domains\Catalog\Services\ImdbDatasetService;
use App\Domains\Catalog\Support\CatalogImdbIds;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Support\Str;

#[Description('Sync IMDb titles: re-download the title.basics dataset and refresh the basics columns on every matching catalog title')]
#[Signature('catalog:sync-titles {--batch=}')]
final class SyncImdbTitles extends ImdbSyncCommand
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
     * Adult rows matched in the catalog but deliberately never written.
     */
    private int $adultSkipped = 0;

    public function __construct(
        ImdbDatasetService $datasets,
        CatalogImdbIds $catalogIds,
        private readonly ImportImdbTitles $importer,
    ) {
        parent::__construct($datasets, $catalogIds);
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

    protected function defaultBatchSize(): int
    {
        return self::BATCH_SIZE;
    }

    protected function heartbeatTag(): string
    {
        return 'imdb titles';
    }

    /**
     * Drop the adult rows, adding them to the run's skip tally.
     *
     * Runs after the catalog-membership probe, so the tally stays catalog-scoped.
     *
     * @param  array<string, array<string, mixed>>  $rows
     * @return array<string, array<string, mixed>>
     */
    #[\Override]
    protected function writable(array $rows): array
    {
        $writable = collect($rows)
            ->reject(fn (array $row): bool => $row['isAdult'] === true)
            ->all();

        $this->adultSkipped += count($rows) - count($writable);

        return $writable;
    }

    /**
     * @param  array<string, array<string, mixed>>  $rows
     */
    protected function import(array $rows): void
    {
        $this->importer->handle($rows);
    }
}
