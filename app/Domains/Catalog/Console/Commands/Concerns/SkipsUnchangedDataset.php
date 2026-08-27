<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Console\Commands\Concerns;

use App\Domains\Catalog\Enums\ImdbDataset;
use App\Domains\Catalog\Services\ImdbDatasetService;
use App\Domains\Catalog\Support\ImdbDatasetMarker;

/**
 * The Last-Modified gate shared by the IMDb dataset legs: probe the dataset's
 * header, skip the whole leg when it matches the stored marker, and advance the
 * marker once the leg has actually succeeded.
 *
 * A host command must declare a `{--force}` option, which the gate reads.
 */
trait SkipsUnchangedDataset
{
    /**
     * The header observed by the gate, carried to the post-run advance so the
     * marker records what we actually downloaded rather than re-probing.
     */
    private ?string $probedLastModified = null;

    private function shouldSyncDataset(ImdbDataset $dataset): bool
    {
        // Probed ahead of the --force check, not after it: a forced run skips
        // the comparison but still needs the header for its marker advance.
        $this->probedLastModified = resolve(ImdbDatasetService::class)->lastModified($dataset);

        // No usable header means we cannot tell whether the dataset moved, so
        // the leg runs ungated rather than risk skipping a real update.
        if ($this->probedLastModified === null) {
            return true;
        }

        if ($this->option('force')) {
            return true;
        }

        if ($this->probedLastModified !== resolve(ImdbDatasetMarker::class)->current($dataset)) {
            return true;
        }

        $this->output->writeln("IMDb {$dataset->filename()} unchanged since the last sync; skipping.");

        return false;
    }

    private function advanceDatasetMarker(ImdbDataset $dataset): void
    {
        // Nothing was served to record, so the stored marker stays as it is and
        // the next run keeps probing until the header comes back.
        if ($this->probedLastModified === null) {
            return;
        }

        resolve(ImdbDatasetMarker::class)->advance($dataset, $this->probedLastModified);
    }
}
