<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Console\Commands;

use App\Domains\Catalog\Actions\ImportImdbAkas;
use App\Domains\Catalog\Enums\ImdbDataset;
use App\Domains\Catalog\Services\ImdbDatasetService;
use App\Domains\Catalog\Support\CatalogImdbIds;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Description('Sync IMDb akas: re-download the title.akas dataset and refresh the aka list on every matching catalog title')]
#[Signature('catalog:sync-akas {--batch=}')]
class SyncImdbAkas extends Command
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

    /**
     * Running count of titles applied, for the per-flush progress heartbeat.
     */
    private int $processed = 0;

    public function __construct(
        private readonly ImdbDatasetService $datasets,
        private readonly CatalogImdbIds $catalogIds,
        private readonly ImportImdbAkas $importer,
    ) {
        parent::__construct();
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

    private function batchSize(): int
    {
        $batch = (int) $this->option('batch');

        return $batch > 0 ? $batch : self::BATCH_SIZE;
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

    /**
     * Narrow the buffered groups to the ones the catalog holds a title for, persist
     * them, and reset the buffer.
     *
     * The catalog-membership probe lives here rather than in the streaming loop so
     * the run never holds the whole catalog's ids in memory. A batch left with
     * nothing to write stays silent: title.akas covers far more titles than the
     * catalog does, so a real run flushes thousands of zero-match batches and a
     * heartbeat for each would bury the signal.
     *
     * @param  array<string, list<array<string, mixed>>>  $batch
     */
    private function flush(array &$batch): void
    {
        $groups = $batch;
        $batch = [];

        if ($groups === []) {
            return;
        }

        $groups = array_intersect_key($groups, $this->catalogIds->existing(array_keys($groups)));

        if ($groups === []) {
            return;
        }

        $this->importer->handle($groups);
        $this->processed += count($groups);
        $this->output->writeln("  [imdb akas {$this->processed}]");
    }
}
