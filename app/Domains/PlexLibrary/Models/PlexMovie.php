<?php

declare(strict_types=1);

namespace App\Domains\PlexLibrary\Models;

use App\Domains\Catalog\Models\Movie;
use App\Domains\PlexLibrary\Database\Factories\PlexMovieFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class PlexMovie extends Model
{
    /** @use HasFactory<PlexMovieFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<Movie, $this>
     */
    public function movie(): BelongsTo
    {
        return $this->belongsTo(Movie::class, '_tmdb_id', '_tmdb_id');
    }

    protected static function newFactory(): Factory
    {
        return PlexMovieFactory::new();
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
            'synced_at' => 'immutable_datetime',
        ];
    }
}
