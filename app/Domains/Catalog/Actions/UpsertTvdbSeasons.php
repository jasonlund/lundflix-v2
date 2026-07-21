<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Actions;

use App\Domains\Catalog\Models\Show;
use App\Domains\Catalog\Support\RawSourceColumns;

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
            $show->seasons()->updateOrCreate(
                ['_tvdb_id' => $season['id']],
                [...RawSourceColumns::map('tvdb', self::RAW_COLUMNS, $season), 'tvdb_synced_at' => now()],
            );
        }

        return count($seasons);
    }
}
