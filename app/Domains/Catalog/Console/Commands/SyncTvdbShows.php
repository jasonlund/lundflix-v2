<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Console\Commands;

use App\Domains\Catalog\Actions\ReindexTouchedRows;
use App\Domains\Catalog\Actions\UpsertTvdbArtworks;
use App\Domains\Catalog\Actions\UpsertTvdbSeasons;
use App\Domains\Catalog\Actions\UpsertTvdbShows;
use App\Domains\Catalog\Enums\SyncFeed;
use App\Domains\Catalog\Services\TvdbApiService;
use App\Domains\Catalog\Support\SyncMarker;
use Carbon\CarbonImmutable;
use Generator;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;

/**
 * Incremental TheTVDB show sync. The /updates `since` is derived from the feed's
 * persisted marker: a 6h overlap re-fetches behind the marker, a first run with no
 * marker reaches back 24h, and the reach is capped at 14 days. A clean run advances
 * the marker to its start time; a run that reports any failure leaves the marker
 * untouched so the missed span is re-covered next run.
 */
#[Description('Sync TheTVDB shows incrementally from the /updates feed since the last run marker')]
#[Signature('catalog:sync-shows-tvdb')]
class SyncTvdbShows extends TvdbShowsCommand
{
    /**
     * The /updates `since` timestamp for this run, resolved from the feed marker in
     * handle() before ids() walks the feed.
     */
    private int $since = 0;

    public function handle(
        TvdbApiService $api,
        UpsertTvdbShows $upsertShows,
        UpsertTvdbArtworks $upsertArtworks,
        UpsertTvdbSeasons $upsertSeasons,
        SyncMarker $marker,
        ReindexTouchedRows $reindexTouchedRows,
    ): int {
        $startedAt = CarbonImmutable::now();

        $this->since = $marker->window(SyncFeed::TvdbShows)->sinceTimestamp();

        $this->output->writeln('Syncing shows…');
        $result = $this->syncIds($this->ids($api), $api, $upsertShows, $upsertArtworks, $upsertSeasons);
        $this->output->writeln('Synced shows in '.$this->secondsSince($startedAt).'s');

        // Any failure means this run didn't cover the whole window, so the marker
        // must not move past it.
        if (! $result->failed) {
            $marker->advance(SyncFeed::TvdbShows, $startedAt);
        }

        // Outside the marker gate on purpose: the ingest indexes nothing itself, so a
        // failed run must still index the rows it did hydrate before its window is
        // retried — the watermark is this run's start, so it covers exactly those.
        $this->reindexTouchedShows($reindexTouchedRows, $startedAt);

        return self::SUCCESS;
    }

    /**
     * Pull the series /updates feed since the marker-derived `since`, yielding each
     * flattened record's numeric `recordId`. No skip-synced: the overlap window plus
     * idempotent upsert re-cover any dropped update on a later run.
     *
     * @return Generator<int, int>
     */
    protected function ids(TvdbApiService $api): Generator
    {
        foreach ($api->updates($this->since, 'series') as $record) {
            if (is_numeric($record['recordId'])) {
                yield (int) $record['recordId'];
            }
        }
    }
}
