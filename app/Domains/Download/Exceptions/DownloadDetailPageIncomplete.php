<?php

declare(strict_types=1);

namespace App\Domains\Download\Exceptions;

use Exception;

/**
 * A required node is absent from a fetched detail page (`/t/<id>`), so the page
 * cannot yield a well-formed DownloadResult — the source markup drifted or the page
 * is a restricted/pulled 200 stub. Unlike a drifted list row (which is skipped so
 * siblings survive), a detail page has no siblings to preserve, so the failure
 * propagates to the caller instead of returning a malformed item.
 */
final class DownloadDetailPageIncomplete extends Exception
{
    public static function forDetailPage(int $id, string $missingNode): self
    {
        return new self("Download detail page [{$id}] is missing its required {$missingNode} — likely download-source markup drift or a restricted-page stub.");
    }
}
