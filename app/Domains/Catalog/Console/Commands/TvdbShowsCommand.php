<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Console\Commands;

use App\Domains\Catalog\Actions\UpsertTvdbArtworks;
use App\Domains\Catalog\Actions\UpsertTvdbShows;
use App\Domains\Catalog\Models\Show;
use App\Domains\Catalog\Services\TvdbApiService;
use Generator;
use Illuminate\Console\Command;

abstract class TvdbShowsCommand extends Command
{
    /**
     * Hydrate and upsert discovered ids in chunks of this size.
     */
    private const int BATCH_SIZE = 1000;

    /**
     * Running count of hydrated shows, for the every-1000th progress heartbeat.
     */
    private int $processed = 0;

    /**
     * The id source each concrete command feeds into the shared pipeline.
     *
     * @return iterable<int, int>
     */
    abstract protected function ids(TvdbApiService $api): iterable;

    /**
     * Hydrate the streamed ids in chunks, upserting each chunk's non-404 shows and
     * their artworks. A chunk whose upsert throws is reported and skipped so one bad
     * batch can't abort the ingest. Returns the ids that failed to sync: each chunk's
     * pooled `failedIds` unioned with every id in a chunk that threw.
     *
     * @param  iterable<int, int>  $ids
     * @return list<int>
     */
    protected function syncIds(
        iterable $ids,
        TvdbApiService $api,
        UpsertTvdbShows $upsertShows,
        UpsertTvdbArtworks $upsertArtworks,
    ): array {
        $failed = [];
        $chunk = [];

        foreach ($ids as $id) {
            $chunk[] = $id;

            if (count($chunk) >= self::BATCH_SIZE) {
                $failed = [...$failed, ...$this->syncChunkSafely($chunk, $api, $upsertShows, $upsertArtworks)];
                $chunk = [];
            }
        }

        if ($chunk !== []) {
            $failed = [...$failed, ...$this->syncChunkSafely($chunk, $api, $upsertShows, $upsertArtworks)];
        }

        return $failed;
    }

    /**
     * Run one chunk, reporting rather than propagating a failure so one bad batch
     * (a transient API failure or a single malformed record) can't abort the entire
     * ingest and silently truncate the catalog — the loop moves on to the next. On a
     * throw the whole chunk's ids are returned as failed; otherwise the pooled misses.
     *
     * @param  array<int, int>  $ids
     * @return list<int>
     */
    private function syncChunkSafely(
        array $ids,
        TvdbApiService $api,
        UpsertTvdbShows $upsertShows,
        UpsertTvdbArtworks $upsertArtworks,
    ): array {
        try {
            return $this->syncChunk($ids, $api, $upsertShows, $upsertArtworks);
        } catch (\Throwable $e) {
            report($e);

            return array_values($ids);
        }
    }

    /**
     * Hydrate one chunk of ids, upsert the non-404 shows, then persist each hydrated
     * payload's artworks against its freshly upserted show row. Returns the pooled
     * `failedIds` — ids whose hydration request failed outright.
     *
     * @param  array<int, int>  $ids
     * @return list<int>
     */
    private function syncChunk(
        array $ids,
        TvdbApiService $api,
        UpsertTvdbShows $upsertShows,
        UpsertTvdbArtworks $upsertArtworks,
    ): array {
        // seriesMany() returns the raw TheTVDB `{status, data}` envelope per id (or
        // null on 404); unwrap to the `data` series payload and drop the misses.
        $pooled = $api->seriesMany($ids);

        $payloads = array_values(array_filter(array_map(
            fn (?array $response): ?array => $response['data'] ?? null,
            $pooled->results,
        )));

        if ($payloads === []) {
            return $this->failedInts($pooled->failedIds);
        }

        $upsertShows->handle($payloads);

        // Heartbeat: print every 1000th hydrated title. spin()/progress() render
        // nothing under sync:catalog's nested Artisan::call, so this plain line
        // is the only visible movement; the label distinguishes this phase.
        foreach ($payloads as $payload) {
            if (++$this->processed % 1000 === 0) {
                $this->output->writeln("  [tvdb shows {$this->processed}] ".($payload['name'] ?? '—'));
            }
        }

        $shows = Show::query()
            ->whereIn('_tvdb_id', array_column($payloads, 'id'))
            ->get()
            ->keyBy('_tvdb_id');

        foreach ($payloads as $payload) {
            $show = $shows->get($payload['id']);

            if ($show instanceof Show) {
                $upsertArtworks->handle($show, $payload['artworks'] ?? []);
            }
        }

        return $this->failedInts($pooled->failedIds);
    }

    /**
     * Cap the streamed ids at `--limit`, stopping mid-crawl once enough ids are
     * yielded rather than materializing the whole crawl before slicing. Null
     * `--limit` yields every id.
     *
     * @param  iterable<int, int>  $ids
     * @return Generator<int, int>
     */
    protected function limited(iterable $ids): Generator
    {
        $limit = $this->option('limit');
        $limit = $limit === null ? null : (int) $limit;
        $yielded = 0;

        foreach ($ids as $id) {
            yield $id;

            $yielded++;

            if ($limit !== null && $yielded >= $limit) {
                return;
            }
        }
    }

    /**
     * `PooledResult::failedIds` is typed `list<int|string>` because the shared
     * pooling concern serves string-id sources too, but this pipeline's crawl ids
     * are ints and the base contract returns `list<int>` — normalize to honor it.
     *
     * @param  list<int|string>  $failedIds
     * @return list<int>
     */
    private function failedInts(array $failedIds): array
    {
        return array_map(intval(...), $failedIds);
    }
}
