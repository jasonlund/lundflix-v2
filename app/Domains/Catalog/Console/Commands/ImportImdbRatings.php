<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Console\Commands;

use App\Domains\Catalog\Actions\UpdateImdbRatings;
use App\Domains\Catalog\Enums\ImdbDataset;
use App\Domains\Catalog\Services\ImdbDatasetService;
use Illuminate\Console\Command;
use Symfony\Component\Console\Helper\ProgressBar;

class ImportImdbRatings extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'imdb:import-ratings';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Download the IMDb title.ratings dataset and update ratings on catalog titles';

    /**
     * Flush the accumulated ratings buffer once it reaches this size.
     */
    private const BATCH_SIZE = 5000;

    public function __construct(
        private ImdbDatasetService $datasets,
        private UpdateImdbRatings $updater,
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $path = $this->datasets->download(ImdbDataset::TitleRatings);

        try {
            $bar = $this->output->createProgressBar($this->datasets->count($path));

            /** @var array<string, array{num_votes: int, average_rating: float}> $batch */
            $batch = [];

            foreach ($this->datasets->rows($path, ImdbDataset::TitleRatings) as $row) {
                $batch[$row['tconst']] = [
                    'num_votes' => $row['numVotes'],
                    'average_rating' => $row['averageRating'],
                ];

                if (count($batch) >= self::BATCH_SIZE) {
                    $this->flush($batch, $bar);
                }
            }

            $this->flush($batch, $bar);

            $bar->finish();
            $this->newLine();
        } finally {
            @unlink($path);
        }

        return self::SUCCESS;
    }

    /**
     * Persist the accumulated ratings buffer, advance the progress bar, and reset it.
     *
     * @param  array<string, array{num_votes: int, average_rating: float}>  $batch
     */
    private function flush(array &$batch, ProgressBar $bar): void
    {
        if ($batch === []) {
            return;
        }

        $this->updater->handle($batch);
        $bar->advance(count($batch));
        $batch = [];
    }
}
