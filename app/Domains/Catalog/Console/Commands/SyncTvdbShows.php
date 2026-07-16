<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Console\Commands;

use App\Domains\Catalog\Actions\UpsertTvdbArtworks;
use App\Domains\Catalog\Actions\UpsertTvdbShows;
use App\Domains\Catalog\Services\TvdbApiService;
use Generator;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;

#[Description('Sync TheTVDB shows from the rolling 14-day updates feed')]
#[Signature('catalog:sync-shows-tvdb {--limit=}')]
class SyncTvdbShows extends TvdbShowsCommand
{
    /**
     * Rolling overlap window fed to the /updates feed. Far wider than the 12h
     * sync cadence: a dropped update is re-covered by ~28 later runs, and
     * idempotent upserts make the re-processing harmless — so the window itself
     * is the self-heal, needing zero persisted state (no marker, no skip set).
     */
    private const int WINDOW_DAYS = 14;

    public function handle(
        TvdbApiService $api,
        UpsertTvdbShows $upsertShows,
        UpsertTvdbArtworks $upsertArtworks,
    ): int {
        $this->output->writeln('Syncing shows…');
        $this->syncIds($this->limited($this->ids($api)), $api, $upsertShows, $upsertArtworks);

        return self::SUCCESS;
    }

    /**
     * Pull the series /updates feed since `now − WINDOW_DAYS`, yielding each
     * flattened record's numeric `recordId`. No skip-synced: the overlap window
     * plus idempotent upsert re-cover any dropped update on a later run.
     *
     * @return Generator<int, int>
     */
    protected function ids(TvdbApiService $api): Generator
    {
        $since = now()->subDays(self::WINDOW_DAYS)->timestamp;

        foreach ($api->updates($since, 'series') as $record) {
            if (is_numeric($record['recordId'])) {
                yield (int) $record['recordId'];
            }
        }
    }
}
