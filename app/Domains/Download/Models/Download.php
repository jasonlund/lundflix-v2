<?php

declare(strict_types=1);

namespace App\Domains\Download\Models;

use App\Domains\Catalog\Models\Movie;
use App\Domains\Catalog\Models\Show;
use App\Domains\Download\Database\Factories\DownloadFactory;
use App\Domains\Download\Enums\Category;
use App\Domains\Download\Enums\Codec;
use App\Domains\Download\Enums\Quality;
use App\Domains\Download\Enums\ReleaseTag;
use App\Domains\Download\Enums\Source;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class Download extends Model
{
    /** @use HasFactory<DownloadFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<Movie, $this>
     */
    public function movie(): BelongsTo
    {
        return $this->belongsTo(Movie::class, '_imdb_id', '_imdb_id');
    }

    /**
     * @return BelongsTo<Show, $this>
     */
    public function show(): BelongsTo
    {
        return $this->belongsTo(Show::class, '_imdb_id', '_imdb_id');
    }

    protected static function newFactory(): Factory
    {
        return DownloadFactory::new();
    }

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    protected function casts(): array
    {
        return [
            '_provider_category' => Category::class,
            '_provider_files' => 'array',
            '_provider_description' => 'array',
            '_provider_published_at' => 'immutable_datetime',
            'quality' => Quality::class,
            'codec' => Codec::class,
            'source' => Source::class,
            'release_tag' => ReleaseTag::class,
            'is_rar' => 'boolean',
            'index_synced_at' => 'immutable_datetime',
            'rss_synced_at' => 'immutable_datetime',
            'detail_synced_at' => 'immutable_datetime',
            'filelist_synced_at' => 'immutable_datetime',
        ];
    }
}
