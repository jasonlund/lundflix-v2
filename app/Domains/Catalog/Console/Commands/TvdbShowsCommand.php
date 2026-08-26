<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Console\Commands;

use App\Domains\Catalog\Actions\UpsertTvdbArtworks;
use App\Domains\Catalog\Actions\UpsertTvdbSeasons;
use App\Domains\Catalog\Actions\UpsertTvdbShows;
use App\Domains\Catalog\Data\SyncIdsResult;
use App\Domains\Catalog\Exceptions\TvdbAuthenticationFailed;
use App\Domains\Catalog\Exceptions\TvdbRequestFailed;
use App\Domains\Catalog\Models\Show;
use App\Domains\Catalog\Services\TvdbApiService;
use App\Domains\Catalog\Support\Batches;
use Illuminate\Console\Command;

abstract class TvdbShowsCommand extends Command
{
    /** Decoded `/series/{id}/extended` bodies held live at once (~150 KB each) — see GUIDELINES.md. */
    private const int BATCH_SIZE = 250;

    private int $processed = 0;

    /**
     * The id source each concrete command feeds into the shared pipeline.
     *
     * @return iterable<int, int>
     */
    abstract protected function ids(TvdbApiService $api): iterable;

    /**
     * Only a command that retries its own failures in-run needs the id list; gating
     * on the boolean alone would otherwise hold a whole window's ids nothing reads.
     */
    protected function collectsFailedIds(): bool
    {
        return false;
    }

    /**
     * Chunked lazily, so a full crawl's millions of ids are never materialized —
     * only the chunk in flight is held.
     *
     * @param  iterable<int, int>  $ids
     */
    protected function syncIds(
        iterable $ids,
        TvdbApiService $api,
        UpsertTvdbShows $upsertShows,
        UpsertTvdbArtworks $upsertArtworks,
        UpsertTvdbSeasons $upsertSeasons,
    ): SyncIdsResult {
        $failed = false;
        $failedIds = [];

        foreach (Batches::of($ids, self::BATCH_SIZE) as $chunk) {
            $result = $this->syncChunkSafely($chunk, $api, $upsertShows, $upsertArtworks, $upsertSeasons);
            $failed = $failed || $result->failed;
            $failedIds = [...$failedIds, ...$result->failedIds];
        }

        return new SyncIdsResult($failed, $failedIds);
    }

    /**
     * The `catch` is narrowed on purpose: any other exception (e.g. a DB
     * QueryException — a genuine bug) propagates rather than being masked as a
     * retryable fetch miss.
     *
     * @param  array<int, int>  $ids
     */
    private function syncChunkSafely(
        array $ids,
        TvdbApiService $api,
        UpsertTvdbShows $upsertShows,
        UpsertTvdbArtworks $upsertArtworks,
        UpsertTvdbSeasons $upsertSeasons,
    ): SyncIdsResult {
        try {
            return $this->syncChunk($ids, $api, $upsertShows, $upsertArtworks, $upsertSeasons);
        } catch (TvdbRequestFailed|TvdbAuthenticationFailed $e) {
            report($e);

            return new SyncIdsResult(true, $this->collectsFailedIds() ? array_values($ids) : []);
        }
    }

    /**
     * @param  array<int, int>  $ids
     */
    private function syncChunk(
        array $ids,
        TvdbApiService $api,
        UpsertTvdbShows $upsertShows,
        UpsertTvdbArtworks $upsertArtworks,
        UpsertTvdbSeasons $upsertSeasons,
    ): SyncIdsResult {
        // seriesMany() returns the raw TheTVDB `{status, data}` envelope per id (null
        // on 404) — unwrap to the series payload below.
        $pooled = $api->seriesMany($ids);

        $payloads = array_values(array_filter(array_map(
            fn (?array $response): ?array => $response['data'] ?? null,
            $pooled->results,
        )));

        if ($payloads === []) {
            return $this->chunkResult($pooled->failedIds);
        }

        $upsertShows->handle($payloads);

        foreach ($payloads as $payload) {
            if (++$this->processed % 1000 === 0) {
                $this->output->writeln("  [tvdb shows {$this->processed}] ".($payload['name'] ?? '—'));
            }
        }

        // Downstream reads only the PK (morph relation + season FK) and the keyBy
        // column, so hydrating whole Show models per batch is dead weight.
        $shows = Show::query()
            ->select(['id', '_tvdb_id'])
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

        return $this->chunkResult($pooled->failedIds);
    }

    /**
     * `PooledResult::failedIds` is typed `list<int|string>` because the shared pooling
     * concern serves string-id sources too, but this pipeline's ids are ints —
     * normalize to honor `list<int>`.
     *
     * @param  list<int|string>  $failedIds
     */
    private function chunkResult(array $failedIds): SyncIdsResult
    {
        return new SyncIdsResult(
            $failedIds !== [],
            $this->collectsFailedIds() ? array_map(intval(...), $failedIds) : [],
        );
    }
}
