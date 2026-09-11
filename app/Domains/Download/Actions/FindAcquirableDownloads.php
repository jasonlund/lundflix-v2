<?php

declare(strict_types=1);

namespace App\Domains\Download\Actions;

use App\Domains\Catalog\Data\UnitRef;
use App\Domains\Catalog\Enums\UnitKind;
use App\Domains\Catalog\Models\Episode;
use App\Domains\Catalog\Models\Movie;
use App\Domains\Download\Contracts\FindsAcquirableDownloads;
use App\Domains\Download\Enums\Category;
use App\Domains\Download\Models\Download;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Override;

final readonly class FindAcquirableDownloads implements FindsAcquirableDownloads
{
    #[Override]
    public function for(UnitRef $unit): ?int
    {
        return match ($unit->kind) {
            UnitKind::Movie => $this->forMovie($unit->id),
            UnitKind::Episode => $this->forEpisode($unit->id),
        };
    }

    private function forMovie(int $movieId): ?int
    {
        $movie = Movie::query()->find($movieId);

        if ($movie === null) {
            return null;
        }

        // With neither crosswalk id there is nothing to match on, and an empty match
        // group contributes no WHERE at all — leaving the whole table in the running.
        if ($movie->_imdb_id === null && $movie->_tmdb_id === null) {
            return null;
        }

        return $this->mostAvailable(
            Download::query()->where($this->attributedTo($movie->_imdb_id, $movie->_tmdb_id, Category::Movies)),
        );
    }

    private function forEpisode(int $episodeId): ?int
    {
        $episode = Episode::query()->find($episodeId);
        $show = $episode?->show;

        if ($episode === null || $show === null) {
            return null;
        }

        // Same reason as forMovie(): an empty match group leaves the whole table in play.
        if ($show->_imdb_id === null && $show->_tmdb_id === null) {
            return null;
        }

        // `where($column, null)` is `whereNull`, which would pair this episode with
        // every unparsed row of the show.
        if ($episode->_tvdb_seasonNumber === null || $episode->_tvdb_number === null) {
            return null;
        }

        // An episode file and a season pack both deliver the episode, so they match as
        // one pool and neither kind is preferred: availability alone decides.
        return $this->mostAvailable(
            Download::query()
                ->where($this->attributedTo($show->_imdb_id, $show->_tmdb_id, Category::Tv))
                ->where('season', $episode->_tvdb_seasonNumber)
                ->where(
                    fn (Builder $query): Builder => $query
                        ->where('episode', $episode->_tvdb_number)
                        ->orWhere('is_season_pack', true),
                ),
        );
    }

    /**
     * @param  Builder<Download>  $matches
     */
    private function mostAvailable(Builder $matches): ?int
    {
        $id = $matches
            ->orderByDesc('_provider_availability')
            // `, id DESC` is the same tie-break the committed dumps use for their
            // best-first prefixes: without it, equal availability leaves the winner to
            // the storage engine, so the same catalog unit could resolve to a different
            // row on each read.
            ->orderByDesc('id')
            ->value('id');

        return $id === null ? null : (int) $id;
    }

    /**
     * The rows a title carrying these crosswalk ids owns.
     *
     * Precedence is imdb, then tmdb: a row carrying an imdb id is attributed by that id
     * alone, so its tmdb id decides attribution only when the imdb id is absent. Without
     * that guard a row whose ids disagree is claimed by both titles instead of exactly
     * the one its imdb id names.
     *
     * @return Closure(Builder): void
     */
    private function attributedTo(?string $imdbId, ?int $tmdbId, Category $tmdbCategory): Closure
    {
        // A mirrored row links to a title by either crosswalk id and frequently carries
        // only one of them, so each clause stays conditional — `where($column, null)` is
        // Laravel's spelling of `whereNull`, which would match every unlinked row.
        return function (Builder $query) use ($imdbId, $tmdbId, $tmdbCategory): void {
            if ($imdbId !== null) {
                $query->orWhere('_imdb_id', $imdbId);
            }

            if ($tmdbId !== null) {
                // TMDB numbers movies and series in separate sequences, so one number
                // names two unrelated works and `_tmdb_id` is not a single-type
                // namespace — the column is filled from a pattern spanning both types.
                // `_provider_category` is the only record of which type a row was
                // mirrored from, so the caller names the category its title belongs to;
                // without it a series row would be attributed by a movie's tmdb id, and
                // the reverse. The imdb clause needs no such guard: imdb ids are one
                // global namespace.
                $query->orWhere(
                    fn (Builder $group): Builder => $group
                        ->whereNull('_imdb_id')
                        ->where('_tmdb_id', $tmdbId)
                        ->where('_provider_category', $tmdbCategory),
                );
            }
        };
    }
}
