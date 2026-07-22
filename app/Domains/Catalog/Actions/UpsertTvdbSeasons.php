<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Actions;

use App\Domains\Catalog\Models\Show;
use App\Domains\Catalog\Support\RawSourceColumns;
use App\Domains\Catalog\Support\SourceId;

final class UpsertTvdbSeasons
{
    /**
     * Raw TVDB season keys mapped 1:1 onto `_tvdb_*` columns, value taken raw.
     *
     * @var list<string>
     */
    private const array RAW_COLUMNS = [
        'id', 'seriesId', 'type', 'number', 'image', 'imageType',
    ];

    /**
     * Persist a show's TVDB seasons, deduped on `_tvdb_id` and scoped to the show.
     *
     * @param  array<int, array<string, mixed>>  $seasons
     */
    public function handle(Show $show, array $seasons): int
    {
        foreach ($seasons as $season) {
            // `_tvdb_id` is the `updateOrCreate` conflict key, so the raw native id
            // must normalize to a clean queryable id; a malformed/oversized id
            // becomes null and the row is skipped rather than written null-keyed.
            $tvdbId = SourceId::positiveInt($season['id'] ?? null);

            if ($tvdbId === null) {
                continue;
            }

            $show->seasons()->updateOrCreate(
                ['_tvdb_id' => $tvdbId],
                [...RawSourceColumns::map('tvdb', self::RAW_COLUMNS, $season), 'tvdb_synced_at' => now()],
            );
        }

        return count($seasons);
    }
}
