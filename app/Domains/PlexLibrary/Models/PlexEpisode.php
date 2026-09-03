<?php

declare(strict_types=1);

namespace App\Domains\PlexLibrary\Models;

use App\Domains\Catalog\Models\Episode;
use App\Domains\PlexLibrary\Database\Factories\PlexEpisodeFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class PlexEpisode extends Model
{
    /** @use HasFactory<PlexEpisodeFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<Episode, $this>
     */
    public function episode(): BelongsTo
    {
        return $this->belongsTo(Episode::class, '_tvdb_id', '_tvdb_id');
    }

    /**
     * @return BelongsTo<PlexShow, $this>
     */
    public function plexShow(): BelongsTo
    {
        return $this->belongsTo(PlexShow::class);
    }

    /**
     * @return BelongsTo<PlexSeason, $this>
     */
    public function plexSeason(): BelongsTo
    {
        return $this->belongsTo(PlexSeason::class);
    }

    protected static function newFactory(): Factory
    {
        return PlexEpisodeFactory::new();
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
        ];
    }
}
