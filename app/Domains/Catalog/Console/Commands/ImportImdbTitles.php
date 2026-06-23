<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Console\Commands;

use App\Domains\Catalog\Actions\UpsertMovies;
use App\Domains\Catalog\Actions\UpsertShows;
use App\Domains\Catalog\Enums\ImdbDataset;
use App\Domains\Catalog\Enums\TitleType;
use App\Domains\Catalog\Services\ImdbDatasetService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

use function Laravel\Prompts\progress;
use function Laravel\Prompts\spin;

#[Description('Download the IMDb title.basics dataset and upsert movies and shows')]
#[Signature('imdb:import-titles')]
class ImportImdbTitles extends Command
{
    /**
     * Flush an accumulated title buffer once it reaches this size.
     */
    private const int BATCH_SIZE = 1000;

    public function handle(
        ImdbDatasetService $service,
        UpsertMovies $upsertMovies,
        UpsertShows $upsertShows,
    ): int {
        $file = spin(fn (): string => $service->download(ImdbDataset::TitleBasics), 'Downloading title.basics…');

        try {
            $total = spin(fn (): int => $service->count($file, ImdbDataset::TitleBasics), 'Counting titles…');

            $bar = progress(label: 'Importing titles', steps: $total);
            $bar->start();

            $movies = [];
            $shows = [];

            foreach ($service->rows($file, ImdbDataset::TitleBasics) as $row) {
                if (TitleType::from($row['titleType'])->isShow()) {
                    $shows[] = $row;
                } else {
                    $movies[] = $row;
                }

                if (count($movies) >= self::BATCH_SIZE) {
                    $upsertMovies->handle($movies);
                    $movies = [];
                }

                if (count($shows) >= self::BATCH_SIZE) {
                    $upsertShows->handle($shows);
                    $shows = [];
                }

                $bar->advance();
            }

            if ($movies !== []) {
                $upsertMovies->handle($movies);
            }

            if ($shows !== []) {
                $upsertShows->handle($shows);
            }

            $bar->finish();
        } finally {
            @unlink($file);
        }

        return self::SUCCESS;
    }
}
