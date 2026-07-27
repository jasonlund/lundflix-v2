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
     * Lower than the titles sync's 2000 because each buffered value is one large
     * json blob holding an unbounded number of a title's aka rows (popular titles
     * carry 100+). The binding cap is therefore MySQL's max_allowed_packet on the
     * bulk CASE statement's total payload, not the 65,535 placeholder limit.
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

        // The whole catalog's ids up front: title.akas is tens of millions of
        // rows, so a membership query per row is not an option.
        $ids = $this->catalogIds->all();
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

                // Closing runs before the membership skip so $groupId tracks the
                // stream itself: skipping first would hold a finished group open
                // across every unmatched title that follows it.
                if ($titleId !== $groupId) {
                    $this->closeGroup($groupId, $group, $batch, $size);
                    $groupId = $titleId;
                }

                if (! isset($ids[$titleId])) {
                    continue;
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
     * Persist the accumulated akas buffer, emit a progress heartbeat, and reset it.
     *
     * @param  array<string, list<array<string, mixed>>>  $batch
     */
    private function flush(array &$batch): void
    {
        if ($batch === []) {
            return;
        }

        $this->importer->handle($batch);
        $this->processed += count($batch);
        $this->output->writeln("  [imdb akas {$this->processed}]");
        $batch = [];
    }
}
