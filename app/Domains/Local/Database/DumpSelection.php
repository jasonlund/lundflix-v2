<?php

declare(strict_types=1);

namespace App\Domains\Local\Database;

final class DumpSelection
{
    /**
     * Child `media` restriction as a bounded top-N subquery per parent type.
     *
     * The derived-table wrapper (`SELECT id FROM (SELECT id … LIMIT n) alias`) is
     * mandatory: MySQL rejects `LIMIT` directly inside an `IN (subquery)`.
     */
    public static function mediaWhere(int $movieLimit, int $showLimit): string
    {
        $branches = [];

        if ($movieLimit > 0) {
            $branches[] = "(mediable_type = 'movie' AND mediable_id IN "
                .'(SELECT id FROM (SELECT id FROM movies ORDER BY _tmdb_popularity DESC, id DESC '
                ."LIMIT {$movieLimit}) m))";
        }

        if ($showLimit > 0) {
            $branches[] = "(mediable_type = 'show' AND mediable_id IN "
                .'(SELECT id FROM (SELECT id FROM shows ORDER BY _tmdb_popularity DESC, id DESC '
                ."LIMIT {$showLimit}) s))";
        }

        if ($branches === []) {
            return '1=0';
        }

        return implode(' OR ', $branches);
    }

    /**
     * Child `seasons` restriction keyed to the same bounded top-N shows.
     *
     * The derived-table wrapper (`SELECT id FROM (SELECT id … LIMIT n) alias`) is
     * mandatory: MySQL rejects `LIMIT` directly inside an `IN (subquery)`.
     */
    public static function seasonsWhere(int $showLimit): string
    {
        if ($showLimit <= 0) {
            return '1=0';
        }

        return 'show_id IN (SELECT id FROM (SELECT id FROM shows ORDER BY _tmdb_popularity DESC, id DESC '
            ."LIMIT {$showLimit}) s)";
    }
}
