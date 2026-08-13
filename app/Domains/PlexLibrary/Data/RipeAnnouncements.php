<?php

declare(strict_types=1);

namespace App\Domains\PlexLibrary\Data;

final readonly class RipeAnnouncements
{
    /**
     * @param  list<int>  $movieIds
     * @param  list<int>  $episodeIds
     */
    public function __construct(
        public array $movieIds,
        public array $episodeIds,
    ) {}
}
