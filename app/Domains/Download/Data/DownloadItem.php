<?php

declare(strict_types=1);

namespace App\Domains\Download\Data;

use App\Domains\Download\Enums\Codec;
use App\Domains\Download\Enums\Quality;
use App\Domains\Download\Enums\ReleaseTag;
use App\Domains\Download\Enums\Source;
use Illuminate\Support\Collection;
use Spatie\LaravelData\Data;

class DownloadItem extends Data
{
    /**
     * @param  Collection<int, DownloadFile>|null  $files
     */
    public function __construct(
        public int $id,
        public string $name,
        public string $filename,
        public ?Quality $quality,
        public Codec $codec,
        public Source $source,
        public ReleaseTag $releaseTag,
        public bool $isRar,
        public int $sizeBytes,
        public int $availability,
        public int $demand,
        public ?Collection $files = null,
    ) {}
}
