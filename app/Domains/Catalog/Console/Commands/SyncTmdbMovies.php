<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Console\Commands;

use App\Domains\Catalog\Actions\UpsertTmdbImages;
use App\Domains\Catalog\Actions\UpsertTmdbMovies;
use App\Domains\Catalog\Models\Movie;
use App\Domains\Catalog\Services\TmdbApiService;
use App\Domains\Catalog\Services\TmdbExportService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\LazyCollection;

use function Laravel\Prompts\progress;
use function Laravel\Prompts\spin;

#[Description('Download the TMDB movie-ids export, hydrate each id, and upsert movies and their images')]
#[Signature('tmdb:sync-movies {--fresh} {--limit=}')]
class SyncTmdbMovies extends Command
{
    /**
     * Hydrate and upsert exported ids in chunks of this size.
     */
    private const int BATCH_SIZE = 1000;

    public function handle(
        TmdbExportService $export,
        TmdbApiService $api,
        UpsertTmdbMovies $upsertMovies,
        UpsertTmdbImages $upsertImages,
    ): int {
        $file = spin(fn (): string => $export->download(), 'Downloading movie-ids export…');

        try {
            $total = spin(fn (): int => $export->count($file), 'Counting movies…');

            $bar = progress(label: 'Syncing movies', steps: $total);
            $bar->start();

            $ids = [];

            foreach ($this->keptRows($export, $file) as $row) {
                $ids[] = (int) $row['id'];

                if (count($ids) >= self::BATCH_SIZE) {
                    $this->syncChunk($ids, $api, $upsertMovies, $upsertImages);
                    $ids = [];
                }

                $bar->advance();
            }

            if ($ids !== []) {
                $this->syncChunk($ids, $api, $upsertMovies, $upsertImages);
            }

            $bar->finish();
        } finally {
            @unlink($file);
        }

        return self::SUCCESS;
    }

    /**
     * Stream the exported rows to process: skip movies already synced (unless
     * `--fresh` reprocesses everything) and cap the result at `--limit`.
     *
     * @return LazyCollection<int, array{id: int|string}>
     */
    private function keptRows(TmdbExportService $export, string $file): LazyCollection
    {
        $skip = $this->option('fresh')
            ? []
            : array_flip(Movie::query()->whereNotNull('tmdb_synced_at')->pluck('_tmdb_id')->filter()->all());

        $rows = $export->rows($file)
            ->reject(fn (array $row): bool => isset($skip[(int) $row['id']]));

        $limit = $this->option('limit');

        return $limit === null ? $rows : $rows->take((int) $limit);
    }

    /**
     * Hydrate one chunk of exported ids, upsert the non-404 movies, then persist
     * each hydrated payload's images against its freshly upserted movie row.
     *
     * @param  array<int, int>  $ids
     */
    private function syncChunk(
        array $ids,
        TmdbApiService $api,
        UpsertTmdbMovies $upsertMovies,
        UpsertTmdbImages $upsertImages,
    ): void {
        $payloads = array_values(array_filter($api->movies($ids)));

        if ($payloads === []) {
            return;
        }

        $upsertMovies->handle($payloads);

        foreach ($payloads as $payload) {
            if (! isset($payload['images'])) {
                continue;
            }

            $movie = Movie::query()->where('_tmdb_id', $payload['id'])->first();

            if ($movie instanceof Movie) {
                $upsertImages->handle($movie, $payload['images']);
            }
        }
    }
}
