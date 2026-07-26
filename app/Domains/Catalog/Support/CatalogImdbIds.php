<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Support;

use App\Domains\Catalog\Models\Movie;
use App\Domains\Catalog\Models\Show;

final class CatalogImdbIds
{
    /**
     * Built once per ingest run over the whole catalog (~157k ids) and then probed
     * once per streamed dataset row, so the shape is a hash set for O(1) `isset`
     * lookups. Read-only walk: stream the single column with `cursor()` rather than
     * hydrating models. Note `cursor()` still buffers the whole result set under
     * MySQL PDO (no true server-side streaming) — fine at this scale, revisit if
     * the catalog grows an order of magnitude.
     *
     * @return array<string, true> every catalog _imdb_id, as a hash set
     */
    public function all(): array
    {
        $ids = [];

        foreach ([Movie::query(), Show::query()] as $query) {
            $rows = $query->select('_imdb_id')->whereNotNull('_imdb_id')->toBase()->cursor();

            foreach ($rows as $row) {
                $ids[(string) $row->_imdb_id] = true;
            }
        }

        return $ids;
    }
}
