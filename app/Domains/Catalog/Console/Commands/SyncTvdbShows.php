<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Console\Commands;

use App\Domains\Catalog\Actions\UpsertTvdbArtworks;
use App\Domains\Catalog\Actions\UpsertTvdbShows;
use App\Domains\Catalog\Models\Show;
use App\Domains\Catalog\Services\TvdbApiService;
use Generator;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Date;

#[Description('Crawl TheTVDB series and upsert shows with their artworks')]
#[Signature('tvdb:sync-shows {--fresh} {--limit=}')]
class SyncTvdbShows extends Command
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
        $ids = [];

        foreach ($this->keptIds($api) as $id) {
            $ids[] = $id;

            if (count($ids) >= self::BATCH_SIZE) {
                $this->syncChunk($ids, $api, $upsertShows, $upsertArtworks);
                $ids = [];
            }
        }

        if ($ids !== []) {
            $this->syncChunk($ids, $api, $upsertShows, $upsertArtworks);
        }

        return self::SUCCESS;
    }

    /**
     * Stream the series ids to process: on `--fresh` crawl every `allSeries`
     * page (advancing the page ourselves, since the service doesn't walk
     * `links.next`) until a page is empty; otherwise pull the `/updates` feed
     * since the latest sync and skip ids already synced. Cap at `--limit`,
     * stopping mid-crawl once enough ids are yielded rather than materializing
     * the whole crawl before slicing.
     *
     * @return Generator<int, int>
     */
    private function keptIds(TvdbApiService $api): Generator
    {
        $ids = $this->option('fresh')
            ? $this->crawlIds($api)
            : $this->updatedIds($api);

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
     * Crawl `allSeries` from page 0, yielding each base record's numeric `id`,
     * until a page returns no records. Non-numeric ids are skipped so a malformed
     * record can't coerce to 0 and waste a `/series/0` hydration.
     *
     * @return Generator<int, int>
     */
    private function crawlIds(TvdbApiService $api): Generator
    {
        $page = 0;

        while (($records = $api->allSeries($page)) !== []) {
            foreach ($records as $record) {
                if (is_numeric($record['id'])) {
                    yield (int) $record['id'];
                }
            }

            $page++;
        }
    }

    /**
     * Pull the series `/updates` feed since the latest synced show, yielding each
     * flattened record's numeric `recordId` that is not already synced. Non-numeric
     * ids are skipped so a malformed record can't coerce to 0.
     *
     * @return Generator<int, int>
     */
    private function updatedIds(TvdbApiService $api): Generator
    {
        $latest = Show::query()->max('tvdb_synced_at');
        $since = $latest === null ? 0 : Date::parse($latest)->timestamp;

        $skip = array_flip(
            Show::query()->whereNotNull('tvdb_synced_at')->pluck('_tvdb_id')->filter()->all(),
        );

        foreach ($api->updates($since, 'series') as $record) {
            if (! is_numeric($record['recordId'])) {
                continue;
            }

            $id = (int) $record['recordId'];

            if (! isset($skip[$id])) {
                yield $id;
            }
        }
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
