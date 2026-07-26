<?php

declare(strict_types=1);

namespace App\Domains\PlexLibrary\Support;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Date;

final class PlexTimestamp
{
    /**
     * Plex sends `addedAt`/`updatedAt` as unix epochs; persist them as datetimes.
     * An absent field arrives as null and stays null rather than collapsing to
     * the epoch origin.
     */
    public static function fromEpoch(?int $epoch): ?CarbonInterface
    {
        return $epoch === null ? null : Date::createFromTimestamp($epoch);
    }
}
