<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Enums;

enum TitleType: string
{
    case Movie = 'movie';
    case TvMovie = 'tvMovie';
    case Short = 'short';
    case TvSpecial = 'tvSpecial';
    case Video = 'video';
    case TvSeries = 'tvSeries';
    case TvMiniSeries = 'tvMiniSeries';

    public function isShow(): bool
    {
        return match ($this) {
            self::TvSeries, self::TvMiniSeries => true,
            default => false,
        };
    }
}
