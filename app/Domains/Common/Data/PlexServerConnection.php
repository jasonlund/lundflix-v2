<?php

declare(strict_types=1);

namespace App\Domains\Common\Data;

final readonly class PlexServerConnection
{
    public function __construct(
        public string $name,
        public string $clientIdentifier,
        public string $accessToken,
        public bool $owned,
        public string $uri,
    ) {}
}
