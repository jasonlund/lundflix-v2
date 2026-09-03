<?php

declare(strict_types=1);

namespace App\Domains\Download\Console\Commands;

use App\Domains\Common\Console\Concerns\EmitsHeartbeat;
use App\Domains\Download\Actions\UpsertDownloads;
use App\Domains\Download\Enums\Category;
use App\Domains\Download\Enums\SyncChannel;
use App\Domains\Download\Models\Download;
use App\Domains\Download\Services\DownloadService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Description('Walk the Movies and Tv index category pages newest-first and upsert each page of results')]
#[Signature('download:sync-index {--fresh}')]
final class SyncDownloadIndex extends Command
{
    use EmitsHeartbeat;

    public function handle(DownloadService $downloads, UpsertDownloads $upsert): int
    {
        $fresh = (bool) $this->option('fresh');

        $this->output->writeln('Syncing download index…');

        foreach (Category::cases() as $category) {
            $this->syncCategory($downloads, $upsert, $category, $fresh);
        }

        $this->output->writeln('Done.');

        return self::SUCCESS;
    }

    /**
     * Walk one category's listing pages newest-first, upserting each page. In the
     * default incremental mode the walk stops as soon as a page carries no unseen
     * ids; `--fresh` disables that short-circuit and walks to the last page.
     */
    private function syncCategory(
        DownloadService $downloads,
        UpsertDownloads $upsert,
        Category $category,
        bool $fresh,
    ): void {
        $tag = "download index {$category->name}";
        $pageNumber = 1;
        $upserted = 0;

        do {
            $page = $downloads->index($category, $pageNumber);

            // An empty listing page means we walked past the last real page.
            if ($page->results->isEmpty()) {
                break;
            }

            $pageIds = $page->results->map(fn ($result): int => $result->downloadId);
            $seen = Download::query()->whereIn('_provider_id', $pageIds)->pluck('_provider_id');

            foreach ($page->results as $result) {
                $upsert->handle($result, $category, SyncChannel::Index);
                $upserted++;
            }

            // Default incremental walk: once a page carries only already-ingested
            // ids, everything older has been synced, so stop this category.
            if (! $fresh && $pageIds->diff($seen)->isEmpty()) {
                break;
            }

            // Heartbeat: a deep category walk is otherwise silent for minutes, so
            // periodically prove the walk is still advancing under a given category.
            // Written plainly rather than through the emitter because a page number
            // is a walk position, not a running total — marking it would make the
            // closing total look already-reported and suppress it.
            if ($pageNumber % 10 === 0) {
                $this->output->writeln("  [{$tag} p{$pageNumber}]");
            }

            $pageNumber++;
        } while ($pageNumber <= $page->lastPage);

        $this->flushTotal($tag, $upserted);
    }
}
