<?php

declare(strict_types=1);

namespace App\Domains\Download\Console\Commands;

use App\Domains\Common\Console\Concerns\EmitsHeartbeat;
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
    use EmitsHeartbeat;

    public function handle(DownloadService $downloads, UpsertDownloads $upsert): int
    {
        $this->output->writeln('Syncing download RSS…');

        foreach (Category::cases() as $category) {
            $upserted = 0;

            foreach ($downloads->rss($category) as $result) {
                $upsert->handle($result, $category, SyncChannel::Rss);
                $upserted++;
            }

            // One feed carries a single small page of recent results, so an
            // interval beat would almost never fire — the closing total per
            // category is the whole cadence.
            $this->flushTotal("download rss {$category->name}", $upserted);
        }

        $this->output->writeln('Done.');

        return self::SUCCESS;
    }
}
