<?php

declare(strict_types=1);

namespace App\Domains\Download\Data;

use Spatie\LaravelData\Data;

final class DownloadDescription extends Data
{
    /**
     * @param  list<string>  $screenshots
     */
    public function __construct(
        public string $html,
        public array $screenshots,
    ) {}
}
