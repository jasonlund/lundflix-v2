<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Enums;

enum SyncFeed
{
    case TvdbShows;
    case TmdbShows;
    case TmdbMovies;

    public function cacheKey(): string
    {
        return match ($this) {
            self::TvdbShows => 'catalog:sync:marker:tvdb_shows',
            self::TmdbShows => 'catalog:sync:marker:tmdb_shows',
            self::TmdbMovies => 'catalog:sync:marker:tmdb_movies',
        };
    }
}
