<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Actions;

use App\Domains\Catalog\Models\Show;
use App\Domains\Catalog\Support\RawSourceColumns;
use App\Domains\Catalog\Support\SourceId;

final class UpsertTvdbEpisodes
{
    /**
     * Raw TVDB episode keys mapped 1:1 onto `_tvdb_*` columns, value taken raw.
     *
     * @var list<string>
     */
    private const array RAW_COLUMNS = [
        'id', 'seriesId', 'name', 'aired', 'runtime', 'overview', 'image',
        'number', 'absoluteNumber', 'seasonNumber', 'finaleType', 'year',
    ];

    /**
     * Persist a show's TVDB episodes, deduped on `_tvdb_id` and scoped to the show.
     *
     * @param  array<int, array<string, mixed>>  $episodes
     */
    public function handle(Show $show, array $episodes): int
    {
        $processed = 0;

        foreach ($episodes as $episode) {
            // `_tvdb_id` is the `updateOrCreate` conflict key, so the raw native id
            // must normalize to a clean queryable id; a malformed/oversized id
            // becomes null and the row is skipped rather than written null-keyed.
            $tvdbId = SourceId::positiveInt($episode['id'] ?? null);

            if ($tvdbId === null) {
                continue;
            }

            $show->episodes()->updateOrCreate(
                ['_tvdb_id' => $tvdbId],
                [...RawSourceColumns::map('tvdb', self::RAW_COLUMNS, $episode), 'tvdb_synced_at' => now()],
            );

            $processed++;
        }

        return $processed;
    }
}
