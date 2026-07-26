<?php

declare(strict_types=1);

namespace App\Domains\PlexLibrary\Models;

use App\Domains\Catalog\Models\Season;
use App\Domains\PlexLibrary\Database\Factories\PlexSeasonFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlexSeason extends Model
{
    /** @use HasFactory<PlexSeasonFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<Season, $this>
     */
    public function season(): BelongsTo
    {
        return $this->belongsTo(Season::class, '_tvdb_id', '_tvdb_id');
    }

    protected static function newFactory(): Factory
    {
        return PlexSeasonFactory::new();
    }

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    protected function casts(): array
    {
        return [
            '_plex_addedAt' => 'immutable_datetime',
            '_plex_updatedAt' => 'immutable_datetime',
            '_tvdb_id' => 'integer',
        ];
    }
}
