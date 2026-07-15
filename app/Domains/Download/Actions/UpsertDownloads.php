<?php

declare(strict_types=1);

namespace App\Domains\Download\Actions;

use App\Domains\Download\Data\DownloadDescription;
use App\Domains\Download\Data\DownloadFile;
use App\Domains\Download\Data\DownloadResult;
use App\Domains\Download\Enums\Category;
use App\Domains\Download\Enums\SyncChannel;
use App\Domains\Download\Models\Download;
use Illuminate\Support\Collection;

class UpsertDownloads
{
    public function handle(DownloadResult $result, Category $category, SyncChannel $channel): Download
    {
        $columns = [
            '_provider_name' => $result->name,
            '_provider_filename' => $result->filename,
            '_provider_subcategory' => $result->subcategory,
            '_provider_size_bytes' => $result->sizeBytes,
            '_provider_availability' => $result->availability,
            '_provider_demand' => $result->demand,
            '_provider_uploader' => $result->uploader,
            '_provider_published_at' => $result->publishedAt,
            '_imdb_id' => $result->imdbId,
            '_tmdb_id' => $result->tmdbId,
            'quality' => $result->quality,
            'codec' => $result->codec,
            'source' => $result->source,
            'release_tag' => $result->releaseTag,
            'is_rar' => $result->isRar,
            '_provider_description' => $result->description instanceof DownloadDescription
                ? ['text' => $result->description->html, 'screenshots' => $result->description->screenshots]
                : null,
            '_provider_files' => $result->files?->map(
                fn (DownloadFile $file): array => ['name' => $file->name, 'size_bytes' => $file->sizeBytes],
            )->all(),
        ];

        // A channel that lacks a field must not overwrite another channel's stored value.
        // Reject only null (not falsey) so is_rar = false and 0 values still write.
        $columns = collect($columns)->reject(fn ($value): bool => $value === null)->all();

        // Category is owned by the discovering listing walk (index/rss). A row only reaches
        // Detail after that walk already set it authoritatively, so Detail must not touch it.
        if ($channel !== SyncChannel::Detail) {
            $columns['_provider_category'] = $category;
        }

        $columns[$channel->syncedAtColumn()] = now();

        if ($result->files instanceof Collection) {
            $columns['filelist_synced_at'] = now();
        }

        return Download::updateOrCreate(['_provider_id' => $result->downloadId], $columns);
    }
}
