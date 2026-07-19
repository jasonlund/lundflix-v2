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
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

#[Description('Crawl every TheTVDB series and upsert shows with their artworks (one-time bootstrap); pass --ids-file (a CSV file of series ids) to re-hydrate specific series instead of crawling')]
#[Signature('catalog:seed-shows-tvdb {--limit=} {--ids-file=}')]
class SeedTvdbShows extends TvdbShowsCommand
{
    public function handle(
        TvdbApiService $api,
        UpsertTvdbShows $upsertShows,
        UpsertTvdbArtworks $upsertArtworks,
    ): int {
        $idsFile = $this->option('ids-file');

        if ($idsFile !== null && $idsFile !== '' && ! File::exists($idsFile)) {
            $this->output->writeln("--ids-file not found: {$idsFile}");

            return self::FAILURE;
        }

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
     * When `--ids-file` is given, yield the numeric ids from that CSV file
     * directly — a recovery path to re-hydrate specific series without crawling.
     * Otherwise crawl `allSeries` from page 0, yielding each base record's numeric
     * `id`, until a page returns no records. Non-numeric ids are skipped either way
     * so a malformed value can't coerce to 0 and waste a `/series/0` hydration.
     *
     * @return Generator<int, int>
     */
    protected function ids(TvdbApiService $api): Generator
    {
        $idsFile = $this->option('ids-file');

        if ($idsFile !== null && $idsFile !== '') {
            yield from $this->requestedIds(File::get($idsFile));

            return;
        }

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

    /**
     * Split the single-line comma-separated CSV `--ids-file` contents, yielding
     * each numeric id as an int.
     *
     * @return Generator<int, int>
     */
    protected function requestedIds(string $contents): Generator
    {
        foreach (explode(',', $contents) as $id) {
            $id = Str::trim($id);

            if (is_numeric($id)) {
                yield (int) $id;
            }
        }
    }
}
