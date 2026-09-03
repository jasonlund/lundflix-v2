<?php

declare(strict_types=1);

namespace App\Domains\Common\Data;

final readonly class PlexAccount
{
    public function __construct(
        public ?int $id,
        public ?string $uuid,
        public ?string $username,
        public ?string $email,
        public ?string $thumb,
    ) {}
}
