<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Contracts;

use App\Domains\Catalog\Data\UnitRef;
use Illuminate\Support\Collection;

/**
 * The published read surface every catalog title exposes, so a consumer never
 * reaches into a Movie's or a Show's source-prefixed columns directly.
 *
 * No tvdbId(): the movies table has no _tvdb_id column, so Movie could not
 * satisfy it.
 */
interface Title
{
    public function catalogId(): int;

    /**
     * Which source supplies the name is the implementor's choice and can vary
     * row to row (Show unions TVDB over TMDB), so a consumer must never treat
     * this as one fixed column.
     */
    public function displayTitle(): ?string;

    public function imdbId(): ?string;

    public function tmdbId(): ?int;

    /**
     * The acquirable units this title contains: a movie yields itself, a show
     * yields its episodes — possibly none, when no episodes have been synced yet.
     * Order carries no meaning.
     *
     * @return Collection<int, UnitRef>
     */
    public function units(): Collection;
}
