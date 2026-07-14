<?php

declare(strict_types=1);

namespace App\Domains\Download\Data;

use Spatie\LaravelData\Data;

class DownloadFile extends Data
{
    public function __construct(
        public string $name,
        public int $sizeBytes,
    ) {}
}
