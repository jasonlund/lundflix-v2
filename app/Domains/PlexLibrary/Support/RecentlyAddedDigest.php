<?php

declare(strict_types=1);

namespace App\Domains\PlexLibrary\Support;

use App\Domains\PlexLibrary\Models\PlexEpisode;
use App\Domains\PlexLibrary\Models\PlexMovie;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

final class RecentlyAddedDigest
{
    /**
     * @param  Collection<int, PlexMovie>  $movies
     * @param  Collection<int, PlexEpisode>  $episodes
     * @return list<string>
     */
    public static function lines(Collection $movies, Collection $episodes): array
    {
        // Each kind is sorted within its own group and the groups are concatenated:
        // one sort over the finished lines would interleave shows among the movies.
        $movieLines = $movies
            ->sortBy(fn (PlexMovie $plexMovie): string => self::rawMovieLine($plexMovie))
            ->map(fn (PlexMovie $plexMovie): string => self::movieLine($plexMovie))
            ->values();

        $showLines = $episodes
            ->groupBy('plex_show_id')
            ->sortBy(fn (Collection $showEpisodes): string => self::showName($showEpisodes))
            ->map(fn (Collection $showEpisodes): string => self::showLine($showEpisodes))
            ->values();

        return $movieLines->concat($showLines)->all();
    }

    private static function movieLine(PlexMovie $plexMovie): string
    {
        // Escaped only here, never in rawMovieLine(), because lines() sorts on that raw
        // form: every escape opens with '&', which would file "<Untitled>" ahead of the
        // digit-led titles it belongs after.
        return self::escapeSlack(self::rawMovieLine($plexMovie));
    }

    /**
     * The line before escaping — and the sort key, so two movies sharing a title
     * break their tie on the year rather than on the order the rows came back in.
     */
    private static function rawMovieLine(PlexMovie $plexMovie): string
    {
        $title = self::movieTitle($plexMovie);
        $year = $plexMovie->movie?->_tmdb_release_date?->year ?? $plexMovie->_plex_year;

        return $year === null ? $title : "{$title} ({$year})";
    }

    private static function movieTitle(PlexMovie $plexMovie): string
    {
        // The catalog match is the curated record; Plex's own values come from
        // the release filename, so they only stand in when nothing matched.
        return $plexMovie->movie?->_tmdb_title ?? $plexMovie->_plex_title;
    }

    /**
     * @param  Collection<int, PlexEpisode>  $episodes
     */
    private static function showLine(Collection $episodes): string
    {
        // Escaped here rather than in showName() because lines() also sorts on that
        // name, and sorting the escaped form would file "AT&T" under "AT&a…".
        $name = self::escapeSlack(self::showName($episodes));

        $spans = $episodes
            ->groupBy('_plex_parentIndex')
            ->sortKeys()
            ->map(fn (Collection $seasonEpisodes, int|string $season): array => self::seasonSpans((int) $season, $seasonEpisodes))
            ->flatten()
            ->implode(', ');

        return "{$name} {$spans}";
    }

    /**
     * @param  Collection<int, PlexEpisode>  $episodes
     */
    private static function showName(Collection $episodes): string
    {
        $plexShow = $episodes->first()->plexShow;

        // Same catalog-over-Plex precedence as the movie line.
        return $plexShow->show?->_tvdb_name ?? $plexShow->_plex_title;
    }

    /**
     * Slack's own three-character escape set — deliberately not htmlspecialchars()/e(),
     * which would also mangle the quotes that legitimately appear in titles.
     * '&' is replaced first so the '&' it emits into '&lt;' isn't re-escaped after.
     */
    private static function escapeSlack(string $text): string
    {
        return Str::replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $text);
    }

    /**
     * @param  Collection<int, PlexEpisode>  $episodes
     * @return list<string>
     */
    private static function seasonSpans(int $season, Collection $episodes): array
    {
        // A season row Plex hasn't sent yet leaves its episode total unknown, and an
        // unknown total degrades to explicit spans rather than a guessed collapse.
        $seasonTotal = $episodes->first()->plexSeason?->_plex_leafCount;

        // These integer columns are uncast, and MySQL can hand them back as strings
        // where sqlite gives ints — an uncast === here or on the episode numbers
        // below would hold across the sqlite test suite and fail in production.
        $wholeSeasonIsNew = $seasonTotal !== null && (int) $seasonTotal === $episodes->count();

        if ($wholeSeasonIsNew) {
            return [sprintf('S%02d', $season)];
        }

        return $episodes
            ->map(fn (PlexEpisode $episode): int => (int) $episode->_plex_index)
            ->sort()
            ->values()
            // $run is the chunk built so far, so its last element is the previous
            // number — anything not one past it opens a new run, splitting the gap.
            ->chunkWhile(fn (int $number, int $key, Collection $run): bool => $number === $run->last() + 1)
            ->map(function (Collection $run) use ($season): string {
                $span = sprintf('S%02dE%02d', $season, $run->first());

                return $run->count() > 1 ? $span.sprintf('-E%02d', $run->last()) : $span;
            })
            ->values()
            ->all();
    }
}
