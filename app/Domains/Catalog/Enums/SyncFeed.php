<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Enums;

enum SyncFeed
{
    case TvdbShows;
    case TvdbEpisodes;
    case TmdbShows;
    case TmdbMovies;

    public function key(): string
    {
        return match ($this) {
            self::TvdbShows => 'tvdb_shows',
            self::TvdbEpisodes => 'tvdb_episodes',
            self::TmdbShows => 'tmdb_shows',
            self::TmdbMovies => 'tmdb_movies',
        };
    }
}
