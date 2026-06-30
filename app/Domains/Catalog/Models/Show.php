<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Models;

use App\Domains\Catalog\Casts\NullableDate;
use App\Domains\Catalog\Database\Factories\ShowFactory;
use App\Domains\Catalog\Enums\Genre;
use App\Domains\Catalog\Enums\TitleType;
use Illuminate\Database\Eloquent\Casts\AsEnumCollection;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Laravel\Scout\Searchable;

class Show extends Model
{
    /** @use HasFactory<ShowFactory> */
    use HasFactory;

    use Searchable;

    /**
     * @return MorphMany<Media, $this>
     */
    public function media(): MorphMany
    {
        return $this->morphMany(Media::class, 'mediable');
    }

    /**
     * @return array<string, mixed>
     */
    public function toSearchableArray(): array
    {
        return [
            'id' => $this->id,
            'imdb_id' => $this->imdb_id,
            'title' => $this->title,
            'start_year' => $this->start_year,
            'num_votes' => $this->num_votes,
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
            'start_year' => 'integer',
            'end_year' => 'integer',
            'runtime' => 'integer',
            'num_votes' => 'integer',
            'average_rating' => 'float',
            'genres' => AsEnumCollection::of(Genre::class),
            'title_type' => TitleType::class,
            '_tmdb_id' => 'integer',
            '_tmdb_first_air_date' => NullableDate::class,
            '_tmdb_popularity' => 'float',
            '_tmdb_vote_average' => 'float',
            '_tmdb_vote_count' => 'integer',
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
            'tvdb_synced_at' => 'datetime',
        ];
    }
}
