<?php

declare(strict_types=1);

namespace App\Domains\Download\Data;

use App\Domains\Download\Enums\Codec;
use App\Domains\Download\Enums\Quality;
use Carbon\CarbonImmutable;
use Spatie\LaravelData\Attributes\WithCast;
use Spatie\LaravelData\Casts\DateTimeInterfaceCast;
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
        // Source feed sends a space-separated timestamp; spatie's cast defaults to ISO-8601, so pin the format.
        #[WithCast(DateTimeInterfaceCast::class, format: 'Y-m-d H:i:s')]
        public CarbonImmutable $uploadedAt,
    ) {}
}
