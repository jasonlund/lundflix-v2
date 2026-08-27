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
#[Signature('catalog:sync-akas {--batch=}')]
final class SyncImdbAkas extends ImdbSyncCommand
{
    /**
     * Default flush size for the accumulated akas buffer; --batch overrides it.
     *
     * Bounds both the raw dataset rows held in memory and the ids each flush's
     * catalog-membership probe binds into its `in (…)` — not the bulk CASE update,
     * which only ever writes the catalog's share of a batch. Memory is the binding
     * constraint here: an entry is a whole title's aka group, matched or not, and a
     * popular title carries 100+ rows — hence a smaller default than the titles sync.
     */
    private const int BATCH_SIZE = 1000;

    public function __construct(
        ImdbDatasetService $datasets,
        CatalogImdbIds $catalogIds,
        private readonly ImportImdbAkas $importer,
    ) {
        parent::__construct($datasets, $catalogIds);
    }

    public function handle(): int
    {
        $path = $this->datasets->download(ImdbDataset::TitleAkas);

        // Plain writeln progress, not a progress bar: bars render nothing
        // under catalog:sync's nested Artisan::call, so a per-flush heartbeat
        // is the only visible movement.
        $this->output->writeln('Importing IMDb akas…');

        $size = $this->batchSize();

        try {
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

            foreach ($this->datasets->rows($path, ImdbDataset::TitleAkas) as $row) {
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
        } finally {
            @unlink($path);
        }

        return self::SUCCESS;
    }

    protected function defaultBatchSize(): int
    {
        return self::BATCH_SIZE;
    }

    protected function heartbeatTag(): string
    {
        return 'imdb akas';
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
