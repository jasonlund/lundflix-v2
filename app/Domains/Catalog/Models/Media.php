<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Models;

use App\Domains\Catalog\Database\Factories\MediaFactory;
use App\Domains\Catalog\Enums\ArtworkType;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Media extends Model
{
    /** @use HasFactory<MediaFactory> */
    use HasFactory;

    private const string CDN_BASE = 'https://image.tmdb.org/t/p';

    public function url(?string $size = null): ?string
    {
        // _tvdb_image is already an absolute TVDB artwork URL; $size does not apply
        if ($this->_tvdb_image !== null) {
            return $this->_tvdb_image;
        }

        if ($this->_tmdb_file_path === null) {
            return null;
        }

        return self::CDN_BASE.'/'.($size ?? $this->type->defaultSize()).$this->_tmdb_file_path;
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function mediable(): MorphTo
    {
        return $this->morphTo('mediable');
    }

    protected static function newFactory(): Factory
    {
        return MediaFactory::new();
    }

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    protected function casts(): array
    {
        return [
            // `type` is our derived ArtworkType (source-agnostic); `_tvdb_type` is TVDB's raw source code, kept separate so no source owns the app's own dimension
            'type' => ArtworkType::class,
            'is_active' => 'boolean',
            '_tmdb_vote_average' => 'float',
            '_tmdb_vote_count' => 'integer',
            '_tmdb_width' => 'integer',
            '_tmdb_height' => 'integer',
            '_tmdb_aspect_ratio' => 'float',
            '_tvdb_score' => 'float',
            '_tvdb_type' => 'integer',
            '_tvdb_width' => 'integer',
            '_tvdb_height' => 'integer',
        ];
    }
}
