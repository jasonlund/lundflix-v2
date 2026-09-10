<?php

declare(strict_types=1);

namespace App\Domains\Download\Contracts;

use App\Domains\Download\Exceptions\DownloadRequestFailed;
use App\Domains\Download\Exceptions\InvalidDownloadCredentials;
use App\Domains\Download\Exceptions\RateLimitExceeded;

interface QueuesDownload
{
    /**
     * Hand the `downloads.id` row to the fetch path, so its file is requested from
     * the download source and stored. The id is the same identity
     * {@see FindsAcquirableDownloads::for()} returns, so the two contracts compose.
     *
     * An id naming no row is a silent no-op: a caller acting on an id read earlier
     * need not re-check the row still exists, and a deleted row yields nothing to
     * request.
     *
     * @throws RateLimitExceeded when the request throttle refuses to admit the request
     * @throws InvalidDownloadCredentials when the source answers with its login page
     * @throws DownloadRequestFailed when the request fails or the bytes never reach disk
     */
    public function queue(int $downloadId): void;
}
