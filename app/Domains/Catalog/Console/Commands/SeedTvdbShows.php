<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Console\Commands;

use App\Domains\Catalog\Actions\UpsertTvdbArtworks;
use App\Domains\Catalog\Actions\UpsertTvdbSeasons;
use App\Domains\Catalog\Actions\UpsertTvdbShows;
use App\Domains\Catalog\Exceptions\TvdbRequestFailed;
use App\Domains\Catalog\Services\TvdbApiService;
use App\Domains\Catalog\Support\SourceId;
use Generator;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

#[Description('Crawl every TheTVDB series and upsert shows with their artworks and seasons (one-time bootstrap); pass --ids-file (a CSV file of series ids) to re-hydrate specific series instead of crawling')]
#[Signature('catalog:seed-shows-tvdb {--limit=} {--ids-file=}')]
class SeedTvdbShows extends TvdbShowsCommand
{
    public function handle(
        TvdbApiService $api,
        UpsertTvdbShows $upsertShows,
        UpsertTvdbArtworks $upsertArtworks,
        UpsertTvdbSeasons $upsertSeasons,
    ): int {
        $idsFile = $this->option('ids-file');

        if ($idsFile !== null && $idsFile !== '') {
            // File::isFile (not File::exists) so a directory path refuses cleanly here
            // rather than reaching File::get() below, which throws for a directory.
            if (! File::isFile($idsFile)) {
                $this->output->writeln("--ids-file not found: {$idsFile}");

                return self::FAILURE;
            }

            $ids = iterator_to_array($this->requestedIds(File::get($idsFile)), false);
            $this->output->writeln('--ids-file parsed '.count($ids).' series ids');

            // Zero valid ids (e.g. a newline dump that split to junk) would otherwise
            // report "0 recovered, 0 still failing" and exit 0 — indistinguishable
            // from a clean run — so fail fast instead of silently succeeding.
            if ($ids === []) {
                $this->output->writeln("--ids-file contained no valid series ids: {$idsFile}");

                return self::FAILURE;
            }
        } else {
            $ids = $this->ids($api);
        }

        $this->output->writeln('Syncing shows…');
        $failed = $this->syncIds($this->limited($ids), $api, $upsertShows, $upsertArtworks, $upsertSeasons);

        // TheTVDB offers no re-download list, so a dropped id has to heal within the
        // same run: one retry pass over the crawl's failures, then report the remainder.
        $stillFailing = $failed === [] ? [] : $this->syncIds($failed, $api, $upsertShows, $upsertArtworks, $upsertSeasons);

        $recovered = count($failed) - count($stillFailing);
        $this->output->writeln("catalog:seed-shows-tvdb retry: {$recovered} recovered, ".count($stillFailing).' still failing');

        if ($stillFailing !== []) {
            $this->output->writeln('  unrecovered ids: '.implode(', ', $stillFailing));
            report(TvdbRequestFailed::forIds($stillFailing));
        }

        return self::SUCCESS;
    }

    /**
     * Crawl `allSeries` from page 0, yielding each base record's id, until a page
     * returns no records. Each candidate routes through `SourceId::positiveInt`, so
     * a malformed value (non-digit, decimal, overflow) is skipped rather than
     * coerced to 0 and wasting a `/series/0` hydration.
     *
     * @return Generator<int, int>
     */
    protected function ids(TvdbApiService $api): Generator
    {
        $page = 0;

        while (($records = $api->allSeries($page)) !== []) {
            foreach ($records as $record) {
                $id = SourceId::positiveInt($record['id'] ?? null);

                if ($id !== null) {
                    yield $id;
                }
            }

            $page++;
        }
    }

    /**
     * Split the `--ids-file` contents on commas and newlines (operator dumps are
     * often newline-separated), yielding each id. Each candidate routes through
     * `SourceId::positiveInt`, so a decimal ("70327.5"), signed, overflow, or junk
     * value is skipped rather than truncated to an unrelated real series id.
     *
     * @return Generator<int, int>
     */
    protected function requestedIds(string $contents): Generator
    {
        foreach (preg_split('/[,\r\n]+/', $contents) as $raw) {
            $id = SourceId::positiveInt(Str::trim($raw));

            if ($id !== null) {
                yield $id;
            }
        }
    }
}
