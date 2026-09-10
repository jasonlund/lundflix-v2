<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Data;

use App\Domains\Catalog\Actions\ReconcileImdbOnlyShows;

/**
 * Outcome of a {@see ReconcileImdbOnlyShows::handle()} pass over one hydrate
 * chunk: the `_tmdb_id`s it stamped and the caller should hydrate, plus whether
 * TMDB failed to answer for any of the chunk's imdb ids.
 *
 * The flag is the whole reason this is not still a bare id list. An unstamped row
 * is either genuinely unresolvable or one TMDB never answered for, and `/find`
 * cannot tell the caller which per row — so the chunk carries the distinction
 * instead, and a failing chunk is left to retry at full rate.
 */
final readonly class ShowCrosswalkResult
{
    /**
     * @param  list<int>  $resolvedIds
     */
    public function __construct(public array $resolvedIds, public bool $failed) {}
}
