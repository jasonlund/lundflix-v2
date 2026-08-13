<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Support;

use App\Domains\Catalog\Console\Commands\TvdbShowsCommand;

/**
 * Outcome of a {@see TvdbShowsCommand::syncIds()}
 * run: whether any id failed at all, plus those ids — carried only when the concrete
 * command opts in via `collectsFailedIds()`.
 */
final readonly class SyncIdsResult
{
    /**
     * @param  list<int>  $failedIds
     */
    public function __construct(public bool $failed, public array $failedIds) {}
}
