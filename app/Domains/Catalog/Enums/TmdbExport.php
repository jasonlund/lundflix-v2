<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Enums;

enum TmdbExport: string
{
    case MovieIds = 'movie_ids';
    case TvSeriesIds = 'tv_series_ids';

    public function filename(string $date): string
    {
        return "{$this->value}_{$date}.json.gz";
    }
}
