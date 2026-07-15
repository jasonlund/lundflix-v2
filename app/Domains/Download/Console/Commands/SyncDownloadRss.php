<?php

declare(strict_types=1);

namespace App\Domains\Download\Console\Commands;

use App\Domains\Download\Actions\UpsertDownloads;
use App\Domains\Download\Enums\Category;
use App\Domains\Download\Enums\SyncChannel;
use App\Domains\Download\Services\DownloadService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Description('Fetch the Movies and Tv mother-category RSS feeds and upsert each mapped result')]
#[Signature('download:sync-rss')]
class SyncDownloadRss extends Command
{
    public function handle(DownloadService $downloads, UpsertDownloads $upsert): int
    {
        foreach (Category::cases() as $category) {
            foreach ($downloads->rss($category) as $result) {
                $upsert->handle($result, $category, SyncChannel::Rss);
            }
        }

        return self::SUCCESS;
    }
}
