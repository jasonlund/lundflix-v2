<?php

declare(strict_types=1);

namespace App\Domains\Common\Data;

final readonly class PlexPin
{
    /**
     * Plex answers 2xx without an id often enough that the hand-off start must be
     * able to report the miss; the union mirrors PlexSession::rememberPin().
     */
    public function __construct(
        public int|string|null $id,
        public ?string $code,
    ) {}
}
