<?php

declare(strict_types=1);

namespace App\Domains\Download\Actions;

use App\Domains\Download\Contracts\QueuesDownload;
use App\Domains\Download\Models\Download;
use App\Domains\Download\Services\DownloadService;
use Override;

final readonly class QueueDownload implements QueuesDownload
{
    public function __construct(private DownloadService $downloads) {}

    #[Override]
    public function queue(int $downloadId): void
    {
        $download = Download::query()->find($downloadId);

        // A row that no longer exists has no source id or filename to fetch with, so
        // there is nothing to request.
        if ($download === null) {
            return;
        }

        // The service's typed failures are the domain's own — let them surface.
        $this->downloads->download((int) $download->_provider_id, (string) $download->_provider_filename);
    }
}
