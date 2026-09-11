<?php

declare(strict_types=1);

namespace App\Domains\Download\Contracts;

use App\Domains\Catalog\Data\UnitRef;
use App\Domains\Catalog\Enums\UnitKind;

interface FindsAcquirableDownloads
{
    /**
     * The `downloads.id` of the row we would fetch for this unit, or null when
     * nothing mirrored matches it. That id is exactly what
     * {@see QueuesDownload::queue()} takes, so a caller composes the two without
     * translating identity in between.
     *
     * Null is an ordinary miss, never a failure — the unit may carry no crosswalk
     * id at all, or nothing mirrored may match the ones it carries. This reads only
     * rows already mirrored locally; it never queries the download source.
     *
     * Provisionally, a {@see UnitKind::Episode} unit ALWAYS resolves to null:
     * episode identity is not mirrored onto a row yet, so a show acquires nothing
     * today. FLIX-313 lands that identity and makes episodes resolve for real.
     */
    public function for(UnitRef $unit): ?int;
}
