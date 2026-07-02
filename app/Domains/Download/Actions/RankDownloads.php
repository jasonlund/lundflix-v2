<?php

declare(strict_types=1);

namespace App\Domains\Download\Actions;

use App\Domains\Download\Data\DownloadResult;
use App\Domains\Download\Enums\Quality;

final class RankDownloads
{
    /**
     * @param  list<DownloadResult>  $results
     * @return list<DownloadResult>
     */
    public function handle(array $results): array
    {
        $filtered = array_filter(
            $results,
            fn (DownloadResult $result): bool => $result->quality instanceof Quality,
        );

        // Precedence: non-rar first, then codec priority, then availability
        // (highest first — $b <=> $a inverts the spaceship to sort descending).
        usort($filtered, fn (DownloadResult $a, DownloadResult $b): int => $a->isRar <=> $b->isRar
            ?: $a->codec->priority() <=> $b->codec->priority()
            ?: $b->availability <=> $a->availability);

        return array_values($filtered);
    }
}
