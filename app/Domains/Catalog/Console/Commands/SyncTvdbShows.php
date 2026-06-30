<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Console\Commands;

use App\Domains\Catalog\Actions\UpsertTvdbArtworks;
use App\Domains\Catalog\Actions\UpsertTvdbShows;
use App\Domains\Catalog\Models\Show;
use App\Domains\Catalog\Services\TvdbApiService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Date;

#[Description('Crawl TheTVDB series and upsert shows with their artworks')]
#[Signature('tvdb:sync-shows {--fresh} {--limit=}')]
final class SyncTvdbShows extends Command
{
    /**
     * Hydrate and upsert discovered ids in chunks of this size.
     */
    private const int BATCH_SIZE = 1000;

    public function handle(
        TvdbApiService $api,
        UpsertTvdbShows $upsertShows,
        UpsertTvdbArtworks $upsertArtworks,
    ): int {
        $ids = $this->keptIds($api);

        foreach (array_chunk($ids, self::BATCH_SIZE) as $chunk) {
            $this->syncChunk($chunk, $api, $upsertShows, $upsertArtworks);
        }

        return self::SUCCESS;
    }

    /**
     * Discover the series ids to process: on `--fresh` crawl every `allSeries`
     * page (advancing the page ourselves, since the service doesn't walk
     * `links.next`) until a page is empty; otherwise pull the `/updates` feed
     * since the latest sync and skip ids already synced. Cap at `--limit`.
     *
     * @return array<int, int>
     */
    private function keptIds(TvdbApiService $api): array
    {
        if ($this->option('fresh')) {
            $ids = $this->crawlIds($api);
        } else {
            $ids = $this->updatedIds($api);
            $ids = $this->rejectSynced($ids);
        }

        $limit = $this->option('limit');

        return $limit === null ? $ids : array_slice($ids, 0, (int) $limit);
    }

    /**
     * Crawl `allSeries` from page 0, collecting each base record's `id`, until a
     * page returns no records.
     *
     * @return array<int, int>
     */
    private function crawlIds(TvdbApiService $api): array
    {
        $ids = [];
        $page = 0;

        while (($records = $api->allSeries($page)) !== []) {
            foreach ($records as $record) {
                $ids[] = (int) $record['id'];
            }

            $page++;
        }

        return $ids;
    }

    /**
     * Pull the series `/updates` feed since the latest synced show, collecting
     * each flattened record's `recordId`.
     *
     * @return array<int, int>
     */
    private function updatedIds(TvdbApiService $api): array
    {
        $latest = Show::query()->max('tvdb_synced_at');
        $since = $latest === null ? 0 : Date::parse($latest)->timestamp;

        return array_map(
            fn (array $record): int => (int) $record['recordId'],
            $api->updates($since, 'series'),
        );
    }

    /**
     * Reject ids that already have a synced show, keyed on `_tvdb_id`.
     *
     * @param  array<int, int>  $ids
     * @return array<int, int>
     */
    private function rejectSynced(array $ids): array
    {
        $skip = array_flip(
            Show::query()->whereNotNull('tvdb_synced_at')->pluck('_tvdb_id')->filter()->all(),
        );

        return array_values(array_filter($ids, fn (int $id): bool => ! isset($skip[$id])));
    }

    /**
     * Hydrate one chunk of ids, upsert the non-404 shows, then persist each
     * hydrated payload's artworks against its freshly upserted show row.
     *
     * @param  array<int, int>  $ids
     */
    private function syncChunk(
        array $ids,
        TvdbApiService $api,
        UpsertTvdbShows $upsertShows,
        UpsertTvdbArtworks $upsertArtworks,
    ): void {
        // seriesMany() returns the raw TheTVDB `{status, data}` envelope per id (or
        // null on 404); unwrap to the `data` series payload and drop the misses.
        $payloads = array_values(array_filter(array_map(
            fn (?array $response): ?array => $response['data'] ?? null,
            $api->seriesMany($ids),
        )));

        if ($payloads === []) {
            return;
        }

        $upsertShows->handle($payloads);

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
    }
}
