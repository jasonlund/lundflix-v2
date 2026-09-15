<?php

declare(strict_types=1);

namespace App\Domains\PlexLibrary\Support;

use Illuminate\Contracts\Database\Query\Builder;

/**
 * The one definition of a mirror row agreeing with a catalog unit, shared by every
 * read that pairs the two so presence and arrival can never disagree on a match.
 * Each predicate expects `movies`/`plex_movies` (or `episodes`, `shows` and
 * `plex_episodes`) to be in scope of the query it is applied to.
 */
final readonly class MirrorMatch
{
    /**
     * Either crosswalk id is enough: a mirror row carries whichever ids the
     * server's own metadata agent resolved, often just one. The is-not-null
     * guards spell out that an unresolved catalog id is not agreement, rather
     * than leaning on SQL's null comparison to imply it.
     */
    public static function movie(Builder $crosswalk): void
    {
        $crosswalk
            ->where(function (Builder $tmdb): void {
                $tmdb
                    ->whereNotNull('movies._tmdb_id')
                    ->whereColumn('plex_movies._tmdb_id', 'movies._tmdb_id');
            })
            ->orWhere(function (Builder $imdb): void {
                $imdb
                    ->whereNotNull('movies._imdb_id')
                    ->whereColumn('plex_movies._imdb_id', 'movies._imdb_id');
            });
    }

    public static function episode(Builder $match): void
    {
        $match
            ->where(self::episodeByCrosswalk(...))
            ->orWhere(self::episodeByPosition(...));
    }

    /**
     * The is-not-null guard spells out that an unresolved catalog id is not
     * agreement, rather than leaning on SQL's null comparison to imply it.
     */
    private static function episodeByCrosswalk(Builder $crosswalk): void
    {
        $crosswalk
            ->whereNotNull('episodes._tvdb_id')
            ->whereColumn('plex_episodes._tvdb_id', 'episodes._tvdb_id');
    }

    /**
     * A mirror row whose metadata agent resolved no guid can only be matched by
     * its place in the show, and reading it as absent would refetch an episode
     * we already hold.
     */
    private static function episodeByPosition(Builder $positional): void
    {
        $positional
            ->whereNull('plex_episodes._tvdb_id')
            ->whereNotNull('episodes._tvdb_seasonNumber')
            ->whereNotNull('episodes._tvdb_number')
            ->whereColumn('plex_episodes._plex_parentIndex', 'episodes._tvdb_seasonNumber')
            ->whereColumn('plex_episodes._plex_index', 'episodes._tvdb_number')
            ->whereExists(function (Builder $mirroredShow): void {
                $mirroredShow
                    ->selectRaw('1')
                    ->from('plex_shows')
                    ->whereColumn('plex_shows.id', 'plex_episodes.plex_show_id')
                    ->whereNotNull('shows._tvdb_id')
                    ->whereColumn('plex_shows._tvdb_id', 'shows._tvdb_id');
            });
    }
}
