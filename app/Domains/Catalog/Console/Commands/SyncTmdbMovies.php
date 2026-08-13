<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Console\Commands;

use App\Domains\Catalog\Actions\UpsertTmdbImages;
use App\Domains\Catalog\Actions\UpsertTmdbMovies;
use App\Domains\Catalog\Enums\SyncFeed;
use App\Domains\Catalog\Models\Movie;
use App\Domains\Catalog\Services\TmdbApiService;
use App\Domains\Catalog\Services\TmdbExportService;
use App\Domains\Catalog\Support\Batches;
use App\Domains\Catalog\Support\SyncMarker;
use App\Domains\Catalog\Support\SyncWindow;
use Carbon\CarbonImmutable;
use Generator;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Database\Eloquent\Builder;

#[Description('Two-phase TMDB movie sync: insert-new from the ids export, then update-changed from the marker-derived changes window')]
#[Signature('catalog:sync-movies {--fresh}')]
class SyncTmdbMovies extends TmdbSyncCommand
{
    private const string EXPORT = 'movie_ids';

    private UpsertTmdbMovies $upsertMovies;

    public function handle(
        TmdbExportService $export,
        TmdbApiService $api,
        UpsertTmdbMovies $upsertMovies,
        UpsertTmdbImages $upsertImages,
        SyncMarker $marker,
    ): int {
        $this->api = $api;
        $this->upsertMovies = $upsertMovies;
        $this->upsertImages = $upsertImages;

        // Run-start, not run-end: updates landing mid-run stay inside the next run's
        // overlap window rather than falling in the gap.
        $startedAt = CarbonImmutable::now();

        $this->output->writeln('Downloading movie-ids export…');
        $file = $export->download(self::EXPORT);

        try {
            $this->output->writeln('Syncing movies…');
            $insertFailed = $this->syncRows($export, $file);
        } finally {
            @unlink($file);
        }

        // --fresh already re-hydrated every exported id, so a changes pass is redundant.
        $changesFailed = false;

        if (! $this->option('fresh')) {
            $changesFailed = $this->updateChanged($marker);
        }

        // A failure means the window wasn't fully covered — the marker must not move
        // past a span still owed to the next run.
        if (! $insertFailed && ! $changesFailed) {
            $marker->advance($this->feed(), $startedAt);
        }

        return self::SUCCESS;
    }

    protected function feed(): SyncFeed
    {
        return SyncFeed::TmdbMovies;
    }

    /**
     * @return Builder<Movie>
     */
    protected function query(): Builder
    {
        return Movie::query();
    }

    protected function entityLabel(): string
    {
        return 'movies';
    }

    protected function heartbeatTag(): string
    {
        return 'movies';
    }

    /**
     * @return iterable<int, int>
     */
    protected function changedIds(SyncWindow $window): iterable
    {
        return $this->api->changedMovieIds($window->startDate(), $window->endDate());
    }

    /**
     * @param  array<int, int>  $ids
     * @return array<int, array<string, mixed>|null>
     */
    protected function hydrate(array $ids): array
    {
        return $this->api->movies($ids);
    }

    /**
     * A `video:true` TMDB record is a promo/trailer, not a real film. It stays
     * present-as-key in the results, so dropping it here never reads as a fetch
     * failure.
     *
     * @param  array<int, array<string, mixed>|null>  $results
     * @return list<array<string, mixed>>
     */
    #[\Override]
    protected function payloads(array $results): array
    {
        return array_values(array_filter(
            $results,
            static fn (?array $payload): bool => $payload !== null && empty($payload['video']),
        ));
    }

    /**
     * @param  list<array<string, mixed>>  $payloads
     */
    protected function upsertPayloads(array $payloads): void
    {
        $this->upsertMovies->handle($payloads);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function payloadTitle(array $payload): ?string
    {
        return $payload['title'] ?? null;
    }

    private function syncRows(TmdbExportService $export, string $file): bool
    {
        $failed = false;

        foreach (Batches::of($this->keptRows($export, $file), self::HYDRATE_SIZE) as $rows) {
            $ids = array_map(static fn (array $row): int => (int) $row['id'], $rows);

            $failed = $this->syncChunkSafely($ids) || $failed;
        }

        return $failed;
    }

    /**
     * The exported rows not already synced (all of them under `--fresh`).
     *
     * Yields row by row rather than returning a set, so a batch hydrates before the
     * next buffer is probed — the interleave is what keeps the resident set bounded.
     *
     * @return Generator<int, array{id: int|string}>
     */
    private function keptRows(TmdbExportService $export, string $file): Generator
    {
        if ($this->option('fresh')) {
            yield from $export->rows($file);

            return;
        }

        foreach (Batches::of($export->rows($file), self::PROBE_SIZE) as $buffer) {
            yield from $this->unsyncedRows($buffer);
        }
    }

    /**
     * @param  array<int, array{id: int|string}>  $buffer
     * @return Generator<int, array{id: int|string}>
     */
    private function unsyncedRows(array $buffer): Generator
    {
        $syncedIds = $this->syncedIdsAmong(
            collect($buffer)->map(static fn (array $row): int => (int) $row['id'])
        )->flip();

        foreach ($buffer as $row) {
            if (! $syncedIds->has((int) $row['id'])) {
                yield $row;
            }
        }
    }
}
