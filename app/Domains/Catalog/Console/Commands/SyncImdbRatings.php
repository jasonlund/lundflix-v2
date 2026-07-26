<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Console\Commands;

use App\Domains\Catalog\Actions\UpdateImdbRatings;
use App\Domains\Catalog\Enums\ImdbDataset;
use App\Domains\Catalog\Services\ImdbDatasetService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Description('Sync IMDb ratings: re-download the title.ratings dataset and refresh votes/rating on every matching catalog title')]
#[Signature('catalog:sync-ratings')]
class SyncImdbRatings extends Command
{
    /**
     * Flush the accumulated ratings buffer once it reaches this size.
     */
    private const int BATCH_SIZE = 5000;

    /**
     * Running count of ratings applied, for the per-flush progress heartbeat.
     */
    private int $processed = 0;

    public function __construct(
        private readonly ImdbDatasetService $datasets,
        private readonly UpdateImdbRatings $updater,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $path = $this->datasets->download(ImdbDataset::TitleRatings);

        // Plain writeln progress, not a progress bar: bars render nothing
        // under catalog:sync's nested Artisan::call, so a per-flush heartbeat
        // is the only visible movement.
        $this->output->writeln('Importing IMDb ratings…');

        try {
            /** @var array<string, array{num_votes: int, average_rating: float}> $batch */
            $batch = [];

            foreach ($this->datasets->rows($path, ImdbDataset::TitleRatings) as $row) {
                $batch[$row['tconst']] = [
                    'num_votes' => $row['numVotes'],
                    'average_rating' => $row['averageRating'],
                ];

                if (count($batch) >= self::BATCH_SIZE) {
                    $this->flush($batch);
                }
            }

            $this->flush($batch);
        } finally {
            @unlink($path);
        }

        return self::SUCCESS;
    }

    /**
     * Persist the accumulated ratings buffer, emit a progress heartbeat, and reset it.
     *
     * @param  array<string, array{num_votes: int, average_rating: float}>  $batch
     */
    private function flush(array &$batch): void
    {
        if ($batch === []) {
            return;
        }

        $this->updater->handle($batch);
        $this->processed += count($batch);
        $this->output->writeln("  [imdb ratings {$this->processed}]");
        $batch = [];
    }
}
