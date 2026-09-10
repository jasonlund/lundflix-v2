<?php

declare(strict_types=1);

namespace App\Domains\Download\Actions;

use App\Domains\Catalog\Data\UnitRef;
use App\Domains\Catalog\Enums\UnitKind;
use App\Domains\Catalog\Models\Movie;
use App\Domains\Download\Contracts\FindsAcquirableDownloads;
use App\Domains\Download\Models\Download;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Override;

final readonly class FindAcquirableDownloads implements FindsAcquirableDownloads
{
    #[Override]
    public function for(UnitRef $unit): ?int
    {
        return match ($unit->kind()) {
            UnitKind::Movie => $this->forMovie($unit->id()),
            // Episode identity is not mirrored onto a row yet, so there is nothing to
            // match an episode on. FLIX-313 lands that identity and replaces this arm.
            UnitKind::Episode => null,
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

        $id = Download::query()
            ->where($this->attributedTo($movie->_imdb_id, $movie->_tmdb_id))
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
    private function attributedTo(?string $imdbId, ?int $tmdbId): Closure
    {
        // A mirrored row links to a title by either crosswalk id and frequently carries
        // only one of them, so each clause stays conditional — `where($column, null)` is
        // Laravel's spelling of `whereNull`, which would match every unlinked row.
        return function (Builder $query) use ($imdbId, $tmdbId): void {
            if ($imdbId !== null) {
                $query->orWhere('_imdb_id', $imdbId);
            }

            if ($tmdbId !== null) {
                $query->orWhere(
                    fn (Builder $group): Builder => $group->whereNull('_imdb_id')->where('_tmdb_id', $tmdbId),
                );
            }
        };
    }
}
