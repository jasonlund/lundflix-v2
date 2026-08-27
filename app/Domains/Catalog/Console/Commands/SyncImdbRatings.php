<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Console\Commands;

use App\Domains\Catalog\Actions\UpdateImdbRatings;
use App\Domains\Catalog\Enums\ImdbDataset;
use App\Domains\Catalog\Services\ImdbDatasetService;
use App\Domains\Catalog\Support\CatalogImdbIds;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;

#[Description('Sync IMDb ratings: re-download the title.ratings dataset and refresh votes/rating on every matching catalog title')]
#[Signature('catalog:sync-ratings {--batch=} {--force}')]
class SyncImdbRatings extends ImdbSyncCommand
{
    /**
     * Bound on both the pre-filter's id probe and the write buffer; --batch
     * overrides it. An entry is a two-field shape, so this leg buffers more
     * ids per flush than the ones carrying whole rows.
     */
    private const int BATCH_SIZE = 5000;

    public function __construct(
        ImdbDatasetService $datasets,
        CatalogImdbIds $catalogIds,
        private readonly UpdateImdbRatings $updater,
    ) {
        parent::__construct($datasets, $catalogIds);
    }

    protected function dataset(): ImdbDataset
    {
        return ImdbDataset::TitleRatings;
    }

    protected function feed(): string
    {
        return 'ratings';
    }

    protected function defaultBatchSize(): int
    {
        return self::BATCH_SIZE;
    }

    protected function stream(string $path): void
    {
        $size = $this->batchSize();

        /** @var array<string, array{numVotes: int, averageRating: float}> $batch */
        $batch = [];

        foreach ($this->matchedRows($path, $size) as $row) {
            // `tconst` is the batch key, so it is dropped from the buffered
            // row rather than repeated on every one of millions of entries.
            $batch[$row['tconst']] = [
                'numVotes' => $row['numVotes'],
                'averageRating' => $row['averageRating'],
            ];

            if (count($batch) >= $size) {
                $this->flush($batch);
            }
        }

        $this->flush($batch);
    }

    /**
     * @param  array<string, array{numVotes: int, averageRating: float}>  $rows
     */
    protected function import(array $rows): void
    {
        $this->updater->handle($rows);
    }
}
