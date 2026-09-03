<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Models;

use App\Domains\Catalog\Casts\NullableDate;
use App\Domains\Catalog\Database\Factories\ShowFactory;
use App\Domains\Catalog\Models\Concerns\Refusable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Laravel\Scout\Searchable;

class Show extends Model
{
    /** @use HasFactory<ShowFactory> */
    use HasFactory;

    use Refusable, Searchable {
        Refusable::shouldBeSearchable insteadof Searchable;
    }

    /**
     * @return MorphMany<Media, $this>
     */
    public function media(): MorphMany
    {
        return $this->morphMany(Media::class, 'mediable');
    }

    /**
     * @return HasMany<Season, $this>
     */
    public function seasons(): HasMany
    {
        return $this->hasMany(Season::class);
    }

    /**
     * @return HasMany<Episode, $this>
     */
    public function episodes(): HasMany
    {
        return $this->hasMany(Episode::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function toSearchableArray(): array
    {
        // TODO: fall back to _tmdb_name / _tmdb_first_air_date (parsed to year) when _tvdb_name / _tvdb_year are null, mirroring Movie::toSearchableArray's source union.
        return [
            'id' => $this->id,
            'imdb_id' => $this->_imdb_id,
            'title' => $this->_tvdb_name,
            'year' => $this->_tvdb_year,
            'num_votes' => $this->_imdb_numVotes,
            'average_rating' => $this->_imdb_averageRating,
        ];
    }

    protected static function newFactory(): Factory
    {
        return ShowFactory::new();
    }

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    protected function casts(): array
    {
        return [
            '_imdb_numVotes' => 'integer',
            '_imdb_averageRating' => 'float',
            '_imdb_startYear' => 'integer',
            '_imdb_endYear' => 'integer',
            '_imdb_runtimeMinutes' => 'integer',
            '_imdb_genres' => 'array',
            '_imdb_akas' => 'array',
            '_imdb_isAdult' => 'boolean',
            '_tmdb_id' => 'integer',
            '_tmdb_first_air_date' => NullableDate::class,
            '_tmdb_popularity' => 'float',
            '_tmdb_vote_average' => 'float',
            '_tmdb_vote_count' => 'integer',
            '_tmdb_adult' => 'boolean',
            '_tmdb_softcore' => 'boolean',
            '_tmdb_genres' => 'array',
            '_tmdb_external_ids' => 'array',
            'tmdb_synced_at' => 'datetime',
            '_tvdb_id' => 'integer',
            '_tvdb_year' => 'integer',
            '_tvdb_averageRuntime' => 'integer',
            '_tvdb_score' => 'float',
            '_tvdb_firstAired' => NullableDate::class,
            '_tvdb_lastAired' => NullableDate::class,
            '_tvdb_status' => 'array',
            '_tvdb_genres' => 'array',
            '_tvdb_remoteIds' => 'array',
            '_tvdb_defaultSeasonType' => 'integer',
            'tvdb_synced_at' => 'datetime',
            'episodes_synced_at' => 'datetime',
        ];
    }
}
