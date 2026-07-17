<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Console\Commands;

use App\Domains\Catalog\Actions\UpsertTvdbArtworks;
use App\Domains\Catalog\Actions\UpsertTvdbShows;
use App\Domains\Catalog\Exceptions\TvdbRequestFailed;
use App\Domains\Catalog\Services\TvdbApiService;
use Generator;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;

#[Description('Crawl every TheTVDB series and upsert shows with their artworks (one-time bootstrap)')]
#[Signature('catalog:seed-shows-tvdb {--limit=}')]
class SeedTvdbShows extends TvdbShowsCommand
{
    public function handle(
        TvdbApiService $api,
        UpsertTvdbShows $upsertShows,
        UpsertTvdbArtworks $upsertArtworks,
    ): int {
        $this->output->writeln('Syncing shows…');
        $failed = $this->syncIds($this->limited($this->ids($api)), $api, $upsertShows, $upsertArtworks);

        // TheTVDB offers no re-download list, so a dropped id has to heal within the
        // same run: one retry pass over the crawl's failures, then report the remainder.
        $stillFailing = $failed === [] ? [] : $this->syncIds($failed, $api, $upsertShows, $upsertArtworks);

        $recovered = count($failed) - count($stillFailing);
        $this->output->writeln("catalog:seed-shows-tvdb retry: {$recovered} recovered, ".count($stillFailing).' still failing');

        if ($stillFailing !== []) {
            $this->output->writeln('  unrecovered ids: '.implode(', ', $stillFailing));
            report(TvdbRequestFailed::forIds($stillFailing));
        }

        return self::SUCCESS;
    }

    /**
     * Crawl `allSeries` from page 0, yielding each base record's numeric `id`,
     * until a page returns no records. Non-numeric ids are skipped so a malformed
     * record can't coerce to 0 and waste a `/series/0` hydration.
     *
     * @return Generator<int, int>
     */
    protected function ids(TvdbApiService $api): Generator
    {
        $page = 0;

        while (($records = $api->allSeries($page)) !== []) {
            foreach ($records as $record) {
                if (is_numeric($record['id'] ?? null)) {
                    yield (int) $record['id'];
                }
            }

            $page++;
        }
    }
}
