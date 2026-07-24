<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Actions;

use App\Domains\Catalog\Models\Season;
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
     * Persist a show's TVDB seasons, deduped globally on `_tvdb_id` (re-parenting a
     * season to this show if TVDB has reassigned it).
     *
     * @param  array<int, array<string, mixed>>  $seasons
     */
    public function handle(Show $show, array $seasons): int
    {
        $processed = 0;

        foreach ($seasons as $season) {
            // `_tvdb_id` is the `updateOrCreate` conflict key, so the raw native id
            // must normalize to a clean queryable id; a malformed/oversized id
            // becomes null and the row is skipped rather than written null-keyed.
            $tvdbId = SourceId::positiveInt($season['id'] ?? null);

            if ($tvdbId === null) {
                continue;
            }

            // Match on `_tvdb_id` alone (the table's global unique key), not the
            // show relation: the same season can already sit under another show
            // from a prior run, so scoping the match to this show would miss it
            // and insert a duplicate that the unique index rejects. Re-parent it
            // to the show whose payload now carries it.
            Season::updateOrCreate(
                ['_tvdb_id' => $tvdbId],
                [...RawSourceColumns::map('tvdb', self::RAW_COLUMNS, $season), 'show_id' => $show->id, 'tvdb_synced_at' => now()],
            );

            $processed++;
        }

        return $processed;
    }
}
