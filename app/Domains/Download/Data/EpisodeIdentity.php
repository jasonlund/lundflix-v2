<?php

declare(strict_types=1);

namespace App\Domains\Download\Data;

final readonly class EpisodeIdentity
{
    public function __construct(
        public int $season,
        public ?int $episode,
        public bool $isSeasonPack,
    ) {}
}
