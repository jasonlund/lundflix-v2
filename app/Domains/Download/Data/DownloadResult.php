<?php

declare(strict_types=1);

namespace App\Domains\Download\Data;

use App\Domains\Download\Enums\Codec;
use App\Domains\Download\Enums\Quality;
use App\Domains\Download\Enums\ReleaseTag;
use App\Domains\Download\Enums\Source;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Spatie\LaravelData\Data;

class DownloadResult extends Data
{
    /**
     * @param  Collection<int, DownloadFile>|null  $files
     */
    public function __construct(
        public int $downloadId,
        public string $name,
        public string $filename,
        public ?Quality $quality,
        public Codec $codec,
        public Source $source,
        public ReleaseTag $releaseTag,
        public int $availability,
        public int $sizeBytes,
        public bool $isRar,
        public ?int $demand = null,
        public ?string $subcategory = null,
        public ?string $uploader = null,
        public ?CarbonImmutable $publishedAt = null,
        public ?string $imdbId = null,
        public ?int $tmdbId = null,
        public ?Collection $files = null,
        public ?DownloadDescription $description = null,
    ) {}
}
