<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Console\Commands;

use App\Domains\Catalog\Actions\UpsertTvdbArtworks;
use App\Domains\Catalog\Actions\UpsertTvdbSeasons;
use App\Domains\Catalog\Actions\UpsertTvdbShows;
use App\Domains\Catalog\Exceptions\TvdbAuthenticationFailed;
use App\Domains\Catalog\Exceptions\TvdbRequestFailed;
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
     * their artworks and seasons. A chunk whose upsert throws is reported and skipped so one bad
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
        UpsertTvdbSeasons $upsertSeasons,
    ): array {
        $failed = [];
        $chunk = [];

        foreach ($ids as $id) {
            $chunk[] = $id;

            if (count($chunk) >= self::BATCH_SIZE) {
                $failed = [...$failed, ...$this->syncChunkSafely($chunk, $api, $upsertShows, $upsertArtworks, $upsertSeasons)];
                $chunk = [];
            }
        }

        if ($chunk !== []) {
            $failed = [...$failed, ...$this->syncChunkSafely($chunk, $api, $upsertShows, $upsertArtworks, $upsertSeasons)];
        }

        return $failed;
    }

    /**
     * Run one chunk, absorbing only a transient TheTVDB API failure so one bad batch
     * can't abort the entire ingest and silently truncate the catalog — the loop moves
     * on and the whole chunk's ids route to the retryable failed set. A non-API
     * exception (e.g. a DB QueryException — a genuine bug) propagates rather than being
     * masked as a retryable miss. Otherwise the pooled misses are returned.
     *
     * @param  array<int, int>  $ids
     * @return list<int>
     */
    private function syncChunkSafely(
        array $ids,
        TvdbApiService $api,
        UpsertTvdbShows $upsertShows,
        UpsertTvdbArtworks $upsertArtworks,
        UpsertTvdbSeasons $upsertSeasons,
    ): array {
        try {
            return $this->syncChunk($ids, $api, $upsertShows, $upsertArtworks, $upsertSeasons);
        } catch (TvdbRequestFailed|TvdbAuthenticationFailed $e) {
            report($e);

            return array_values($ids);
        }
    }

    /**
     * Hydrate one chunk of ids, upsert the non-404 shows, then persist each hydrated
     * payload's artworks and seasons against its freshly upserted show row. Returns the
     * pooled `failedIds` — ids whose hydration request failed outright.
     *
     * @param  array<int, int>  $ids
     * @return list<int>
     */
    private function syncChunk(
        array $ids,
        TvdbApiService $api,
        UpsertTvdbShows $upsertShows,
        UpsertTvdbArtworks $upsertArtworks,
        UpsertTvdbSeasons $upsertSeasons,
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
        // nothing under catalog:sync's nested Artisan::call, so this plain line
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
                $upsertSeasons->handle($show, $payload['seasons'] ?? []);
            }
        }

        return $this->failedInts($pooled->failedIds);
    }

    /**
     * Cap how many ids are hydrated at `--limit`. For the seed crawl (`ids()`
     * pages lazily) this also stops fetching mid-crawl; for the updates-feed sync
     * the feed is walked into an array upstream, so `--limit` bounds hydration
     * only, not fetch or memory. Null `--limit` yields every id.
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
            if ($limit !== null && $yielded >= $limit) {
                return;
            }

            yield $id;
            $yielded++;
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
