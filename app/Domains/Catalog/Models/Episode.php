<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Models;

use App\Domains\Catalog\Casts\NullableDate;
use App\Domains\Catalog\Database\Factories\EpisodeFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class Episode extends Model
{
    /** @use HasFactory<EpisodeFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<Show, $this>
     */
    public function show(): BelongsTo
    {
        return $this->belongsTo(Show::class);
    }

    /**
     * @return BelongsTo<Season, $this>
     */
    public function season(): BelongsTo
    {
        return $this->belongsTo(Season::class);
    }

    protected static function newFactory(): Factory
    {
        return EpisodeFactory::new();
    }

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    protected function casts(): array
    {
        return [
            '_tvdb_id' => 'integer',
            '_tvdb_seriesId' => 'integer',
            '_tvdb_runtime' => 'integer',
            '_tvdb_number' => 'integer',
            '_tvdb_absoluteNumber' => 'integer',
            '_tvdb_seasonNumber' => 'integer',
            '_tvdb_year' => 'integer',
            '_tvdb_aired' => NullableDate::class,
            'tvdb_synced_at' => 'datetime',
        ];
    }
}
