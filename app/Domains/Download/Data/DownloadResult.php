<?php

declare(strict_types=1);

namespace App\Domains\Download\Data;

use App\Domains\Download\Enums\Codec;
use App\Domains\Download\Enums\Quality;
use Spatie\LaravelData\Data;

class DownloadResult extends Data
{
    public function __construct(
        public int $downloadId,
        public string $name,
        public ?Quality $quality,
        public Codec $codec,
        public int $availability,
        public int $sizeBytes,
        public bool $isRar,
    ) {}
}
