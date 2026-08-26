<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Console\Commands;

use App\Domains\Catalog\Actions\ImportImdbAkas;
use App\Domains\Catalog\Enums\ImdbDataset;
use App\Domains\Catalog\Services\ImdbDatasetService;
use App\Domains\Catalog\Support\CatalogImdbIds;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;

#[Description('Sync IMDb akas: re-download the title.akas dataset and refresh the aka list on every matching catalog title')]
#[Signature('catalog:sync-akas {--batch=} {--force}')]
class SyncImdbAkas extends ImdbSyncCommand
{
    /**
     * Bound on both the pre-filter's id probe and the write buffer; --batch
     * overrides it. Memory is the binding constraint here: an entry is a whole
     * title's aka group, and a popular title carries 100+ rows — hence a smaller
     * default than the titles sync.
     */
    private const int BATCH_SIZE = 1000;

    public function __construct(
        ImdbDatasetService $datasets,
        CatalogImdbIds $catalogIds,
        private readonly ImportImdbAkas $importer,
    ) {
        parent::__construct($datasets, $catalogIds);
    }

    protected function dataset(): ImdbDataset
    {
        return ImdbDataset::TitleAkas;
    }

    protected function feed(): string
    {
        return 'akas';
    }

    protected function defaultBatchSize(): int
    {
        return self::BATCH_SIZE;
    }

    /**
     * One title's aka group per buffer entry.
     */
    protected function stream(string $path): void
    {
        $size = $this->batchSize();

        /** @var array<string, list<array<string, mixed>>> $batch */
        $batch = [];

        // The dataset is sorted by titleId, so a title's rows arrive
        // contiguously: accumulate one title's group until the id changes,
        // and buffer that group only on a change — never mid-title, which
        // would split one title's list across two writes and leave the
        // second overwriting the first.
        $groupId = null;
        /** @var list<array<string, mixed>> $group */
        $group = [];

        foreach ($this->matchedRows($path, $size) as $row) {
            $titleId = $row['titleId'];

            if ($titleId !== $groupId) {
                $this->closeGroup($groupId, $group, $batch, $size);
                $groupId = $titleId;
            }

            $group[] = $row;
        }

        // The file's last group never sees an id change, so it is closed
        // here — and closing only buffers it, so the flush that follows is
        // what actually writes the tail of the run.
        $this->closeGroup($groupId, $group, $batch, $size);
        $this->flush($batch);
    }

    /**
     * @param  array<string, list<array<string, mixed>>>  $rows
     */
    protected function import(array $rows): void
    {
        $this->importer->handle($rows);
    }

    /**
     * Move a completed title's group into the buffer, flushing it when full.
     *
     * @param  list<array<string, mixed>>  $group
     * @param  array<string, list<array<string, mixed>>>  $batch
     */
    private function closeGroup(?string $titleId, array &$group, array &$batch, int $size): void
    {
        if ($titleId !== null && $group !== []) {
            $batch[$titleId] = $group;
        }

        $group = [];

        if (count($batch) >= $size) {
            $this->flush($batch);
        }
    }
}
