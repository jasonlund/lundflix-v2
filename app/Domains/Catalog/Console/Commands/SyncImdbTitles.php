<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Console\Commands;

use App\Domains\Catalog\Actions\ImportImdbTitles;
use App\Domains\Catalog\Enums\ImdbDataset;
use App\Domains\Catalog\Services\ImdbDatasetService;
use App\Domains\Catalog\Support\CatalogImdbIds;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;

#[Description('Sync IMDb titles: re-download the title.basics dataset and refresh the basics columns on every matching catalog title')]
#[Signature('catalog:sync-titles {--batch=} {--force}')]
class SyncImdbTitles extends ImdbSyncCommand
{
    /**
     * Bound on both the pre-filter's id probe and the write buffer; --batch overrides it.
     */
    private const int BATCH_SIZE = 2000;

    /**
     * Titles is the widest IMDb write, so the placeholder cap binds here and
     * nowhere else: `BulkCaseUpdate` spends 2 bindings per column per matched row
     * plus 1 for the WHERE IN id — 8 columns × 2 + 1 = 17 a row — against MySQL's
     * 65,535 cap less the single per-statement updated_at binding, so
     * (65535 − 1) / 17 = 3854 rows. Rounded down to 3500 for headroom, since a
     * global scope or a ninth column spends bindings this arithmetic does not see.
     */
    private const int MAX_BATCH_SIZE = 3500;

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

    #[\Override]
    protected function maxBatchSize(): int
    {
        return self::MAX_BATCH_SIZE;
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
     * @param  array<string, array<string, mixed>>  $rows
     */
    protected function import(array $rows): void
    {
        $this->importer->handle($rows);
    }
}
