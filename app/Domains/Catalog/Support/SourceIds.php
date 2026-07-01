<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Support;

/**
 * The three cross-source ids extracted from one payload. Each source ingestor
 * pulls these out of its own payload shape (the genuinely source-specific part);
 * downstream resolution treats every payload uniformly through this triple.
 */
final readonly class SourceIds
{
    public function __construct(
        public ?string $imdbId,
        public ?int $tmdbId,
        public ?int $tvdbId,
    ) {}
}
