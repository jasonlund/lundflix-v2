<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Models;

use App\Domains\Catalog\Database\Factories\MediaFactory;
use App\Domains\Catalog\Enums\ArtworkType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

#[Fillable([
    'mediable_type',
    'mediable_id',
    'type',
    'is_active',
    '_tmdb_file_path',
    '_tmdb_iso_639_1',
    '_tmdb_iso_3166_1',
    '_tmdb_vote_average',
    '_tmdb_vote_count',
    '_tmdb_width',
    '_tmdb_height',
    '_tmdb_aspect_ratio',
])]
class Media extends Model
{
    /** @use HasFactory<MediaFactory> */
    use HasFactory;

    private const string CDN_BASE = 'https://image.tmdb.org/t/p';

    public function url(?string $size = null): string
    {
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
            'type' => ArtworkType::class,
            'is_active' => 'boolean',
            '_tmdb_vote_average' => 'float',
            '_tmdb_vote_count' => 'integer',
            '_tmdb_width' => 'integer',
            '_tmdb_height' => 'integer',
            '_tmdb_aspect_ratio' => 'float',
        ];
    }
}
