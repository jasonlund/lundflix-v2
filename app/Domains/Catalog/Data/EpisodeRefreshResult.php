<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Data;

use App\Domains\Catalog\Actions\RefreshTvdbEpisodes;

/**
 * Outcome of a {@see RefreshTvdbEpisodes::handle()} batch: how many episodes were
 * persisted, which the leg reports as its heartbeat, plus how many ids failed to
 * fetch — the number that gates advancing the sync marker and the exit code, so a
 * failed id is re-covered by the next window instead of being skipped over.
 */
final readonly class EpisodeRefreshResult
{
    public function __construct(public int $episodes, public int $failedEpisodes) {}
}
