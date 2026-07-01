<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Console\Commands;

use App\Domains\Catalog\Actions\UpsertTmdbImages;
use App\Domains\Catalog\Actions\UpsertTmdbShows;
use App\Domains\Catalog\Enums\TmdbExport;
use App\Domains\Catalog\Models\Show;
use App\Domains\Catalog\Services\TmdbApiService;
use App\Domains\Catalog\Services\TmdbExportService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\LazyCollection;

use function Laravel\Prompts\progress;
use function Laravel\Prompts\spin;

#[Description('Download the TMDB tv-series-ids export, hydrate each id, and upsert shows and their images')]
#[Signature('tmdb:sync-shows {--fresh} {--limit=}')]
class SyncTmdbShows extends Command
{
    /**
     * Hydrate and upsert exported ids in chunks of this size.
     */
    private const int BATCH_SIZE = 1000;

    public function handle(
        TmdbExportService $export,
        TmdbApiService $api,
        UpsertTmdbShows $upsertShows,
        UpsertTmdbImages $upsertImages,
    ): int {
        $file = spin(fn (): string => $export->download(TmdbExport::TvSeriesIds), 'Downloading tv-series-ids export…');

        try {
            // The export count only matches the rows actually iterated when --fresh
            // skips nothing and no --limit caps the stream; only then is a
            // determinate bar's total honest. Otherwise drive an indeterminate
            // spinner rather than show a bar that stalls below 100%.
            $determinate = $this->option('fresh') && $this->option('limit') === null;

            if ($determinate) {
                $this->syncDeterminate($export, $file, $api, $upsertShows, $upsertImages);
            } else {
                spin(
                    fn () => $this->syncRows($export, $file, $api, $upsertShows, $upsertImages),
                    'Syncing shows',
                );
            }
        } finally {
            @unlink($file);
        }

        return self::SUCCESS;
    }

    /**
     * Iterate the kept rows with a determinate progress bar — only valid when the
     * export count exactly equals the rows iterated (--fresh, no --limit).
     */
    private function syncDeterminate(
        TmdbExportService $export,
        string $file,
        TmdbApiService $api,
        UpsertTmdbShows $upsertShows,
        UpsertTmdbImages $upsertImages,
    ): void {
        $total = spin(fn (): int => $export->count($file), 'Counting shows…');

        $bar = progress(label: 'Syncing shows', steps: $total);
        $bar->start();

        $this->syncRows($export, $file, $api, $upsertShows, $upsertImages, $bar->advance(...));

        $bar->finish();
    }

    /**
     * Stream the kept rows, hydrating and upserting in BATCH_SIZE chunks. When an
     * $advance callback is given (determinate bar) it fires once per row.
     */
    private function syncRows(
        TmdbExportService $export,
        string $file,
        TmdbApiService $api,
        UpsertTmdbShows $upsertShows,
        UpsertTmdbImages $upsertImages,
        ?callable $advance = null,
    ): void {
        $ids = [];

        foreach ($this->keptRows($export, $file) as $row) {
            // Skip a malformed (non-numeric) export id rather than let (int) coerce
            // it to 0 and waste a /tv/0 hydration call.
            if (! is_numeric($row['id'])) {
                if ($advance !== null) {
                    $advance();
                }

                continue;
            }

            $ids[] = (int) $row['id'];

            if (count($ids) >= self::BATCH_SIZE) {
                $this->syncChunk($ids, $api, $upsertShows, $upsertImages);
                $ids = [];
            }

            if ($advance !== null) {
                $advance();
            }
        }

        if ($ids !== []) {
            $this->syncChunk($ids, $api, $upsertShows, $upsertImages);
        }
    }

    /**
     * Stream the exported rows to process: skip shows already synced (unless
     * `--fresh` reprocesses everything) and cap the result at `--limit`.
     *
     * @return LazyCollection<int, array{id: int|string}>
     */
    private function keptRows(TmdbExportService $export, string $file): LazyCollection
    {
        $skip = $this->option('fresh')
            ? []
            : array_flip(Show::query()->whereNotNull('tmdb_synced_at')->pluck('_tmdb_id')->filter()->all());

        $rows = $export->rows($file)
            ->reject(fn (array $row): bool => isset($skip[(int) $row['id']]));

        $limit = $this->option('limit');

        return $limit === null ? $rows : $rows->take((int) $limit);
    }

    /**
     * Hydrate one chunk of exported ids, upsert the non-404 shows, then persist
     * each hydrated payload's images against its freshly upserted show row.
     *
     * @param  array<int, int>  $ids
     */
    private function syncChunk(
        array $ids,
        TmdbApiService $api,
        UpsertTmdbShows $upsertShows,
        UpsertTmdbImages $upsertImages,
    ): void {
        $payloads = array_values(array_filter($api->tvShows($ids)));

        if ($payloads === []) {
            return;
        }

        $upsertShows->handle($payloads);

        $shows = Show::query()
            ->whereIn('_tmdb_id', array_column($payloads, 'id'))
            ->get()
            ->keyBy('_tmdb_id');

        foreach ($payloads as $payload) {
            if (! isset($payload['images'])) {
                continue;
            }

            $show = $shows->get($payload['id']);

            if ($show instanceof Show) {
                $upsertImages->handle($show, $payload['images']);
            }
        }
    }
}
