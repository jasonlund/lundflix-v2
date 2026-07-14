<?php

declare(strict_types=1);

namespace App\Domains\Download\Data;

use Illuminate\Support\Collection;
use Spatie\LaravelData\Data;

class DownloadPage extends Data
{
    /**
     * @param  Collection<int, DownloadResult>  $results
     */
    public function __construct(
        public Collection $results,
        public int $page,
        public int $lastPage,
    ) {}
}
