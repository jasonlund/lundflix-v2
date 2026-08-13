<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Support;

use App\Domains\Catalog\Models\Movie;
use App\Domains\Catalog\Models\Show;

final class CatalogImdbIds
{
    /**
     * Probed once per flush with just that batch's ids, so nothing about the
     * catalog's size is ever held in memory — two bounded `in (…)` reads (one per
     * table) replace preloading the whole catalog up front.
     *
     * @param  list<string>  $ids
     * @return array<string, true> the probed ids the catalog holds, as a hash set
     */
    public function existing(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $existing = [];

        foreach ([Movie::query(), Show::query()] as $query) {
            $found = $query->select('_imdb_id')->whereIn('_imdb_id', $ids)->toBase()->pluck('_imdb_id');

            foreach ($found as $id) {
                $existing[(string) $id] = true;
            }
        }

        return $existing;
    }
}
