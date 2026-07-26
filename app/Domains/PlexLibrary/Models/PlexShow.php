<?php

declare(strict_types=1);

namespace App\Domains\PlexLibrary\Models;

use App\Domains\Catalog\Models\Show;
use App\Domains\PlexLibrary\Database\Factories\PlexShowFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PlexShow extends Model
{
    /** @use HasFactory<PlexShowFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<Show, $this>
     */
    public function show(): BelongsTo
    {
        return $this->belongsTo(Show::class, '_tvdb_id', '_tvdb_id');
    }

    /**
     * @return HasMany<PlexSeason, $this>
     */
    public function seasons(): HasMany
    {
        return $this->hasMany(PlexSeason::class);
    }

    /**
     * @return HasMany<PlexEpisode, $this>
     */
    public function episodes(): HasMany
    {
        return $this->hasMany(PlexEpisode::class);
    }

    protected static function newFactory(): Factory
    {
        return PlexShowFactory::new();
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
            '_plex_guids' => 'array',
            '_tmdb_id' => 'integer',
            '_tvdb_id' => 'integer',
            'episodes_synced_at' => 'immutable_datetime',
        ];
    }
}
