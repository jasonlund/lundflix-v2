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
#[Signature('catalog:sync-titles {--batch=} {--force}')]
final class SyncImdbTitles extends ImdbSyncCommand
{
    /**
     * Bound on both the pre-filter's id probe and the write buffer; --batch overrides it.
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

    protected function dataset(): ImdbDataset
    {
        return ImdbDataset::TitleBasics;
    }

    protected function feed(): string
    {
        return 'titles';
    }

    protected function defaultBatchSize(): int
    {
        return self::BATCH_SIZE;
    }

    protected function stream(string $path): void
    {
        $size = $this->batchSize();

        /** @var array<string, array<string, mixed>> $batch */
        $batch = [];

        foreach ($this->matchedRows($path, $size) as $row) {
            $batch[$row['tconst']] = $row;

            if (count($batch) >= $size) {
                $this->flush($batch);
            }
        }

        $this->flush($batch);
    }

    /**
     * Drop the adult rows, adding them to the run's skip tally.
     *
     * Runs on rows the pre-filter already matched, so the tally stays
     * catalog-scoped.
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

    #[\Override]
    protected function reportSummary(): void
    {
        $this->output->writeln("Skipped {$this->adultSkipped} adult ".Str::plural('title', $this->adultSkipped).'.');
    }

    /**
     * @param  array<string, array<string, mixed>>  $rows
     */
    protected function import(array $rows): void
    {
        $this->importer->handle($rows);
    }
}
