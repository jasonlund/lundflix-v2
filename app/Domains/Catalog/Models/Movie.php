<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Models;

use App\Domains\Catalog\Casts\NullableDate;
use App\Domains\Catalog\Database\Factories\MovieFactory;
use App\Domains\Catalog\Enums\Genre;
use App\Domains\Catalog\Enums\TitleType;
use Illuminate\Database\Eloquent\Casts\AsEnumCollection;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Laravel\Scout\Searchable;

class Movie extends Model
{
    /** @use HasFactory<MovieFactory> */
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
            'year' => $this->year,
            'num_votes' => $this->num_votes,
        ];
    }

    protected static function newFactory(): Factory
    {
        return MovieFactory::new();
    }

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'runtime' => 'integer',
            'num_votes' => 'integer',
            'average_rating' => 'float',
            'genres' => AsEnumCollection::of(Genre::class),
            'title_type' => TitleType::class,
            '_tmdb_id' => 'integer',
            '_tmdb_release_date' => NullableDate::class,
            '_tmdb_runtime' => 'integer',
            '_tmdb_budget' => 'integer',
            '_tmdb_revenue' => 'integer',
            '_tmdb_popularity' => 'float',
            '_tmdb_vote_average' => 'float',
            '_tmdb_vote_count' => 'integer',
            '_tmdb_video' => 'boolean',
            '_tmdb_genres' => 'array',
            '_tmdb_origin_country' => 'array',
            '_tmdb_production_companies' => 'array',
            '_tmdb_production_countries' => 'array',
            '_tmdb_spoken_languages' => 'array',
            '_tmdb_belongs_to_collection' => 'array',
            '_tmdb_release_dates' => 'array',
            'tmdb_synced_at' => 'datetime',
        ];
    }
}
