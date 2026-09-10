<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Models;

use App\Domains\Catalog\Casts\NullableDate;
use App\Domains\Catalog\Contracts\Title;
use App\Domains\Catalog\Database\Factories\MovieFactory;
use App\Domains\Catalog\Models\Concerns\Refusable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Laravel\Scout\Searchable;

final class Movie extends Model implements Title
{
    /** @use HasFactory<MovieFactory> */
    use HasFactory;

    use Refusable, Searchable;

    public function catalogId(): int
    {
        return $this->id;
    }

    public function displayTitle(): ?string
    {
        return $this->_tmdb_title;
    }

    public function imdbId(): ?string
    {
        return $this->_imdb_id;
    }

    public function tmdbId(): ?int
    {
        return $this->_tmdb_id;
    }

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
            'imdb_id' => $this->_imdb_id,
            'title' => $this->_tmdb_title,
            'year' => $this->_tmdb_release_date?->year,
            'num_votes' => $this->_imdb_numVotes,
            'average_rating' => $this->_imdb_averageRating,
        ];
    }

    /**
     * A title TMDB has stopped serving stays as a row but must not be findable —
     * the catalog can no longer stand behind the copy it holds. The check lives
     * here rather than in Refusable because `shows` has no `tmdb_gone_at` column,
     * and it composes with refusal rather than replacing it: a refused title must
     * stay out of the index even once TMDB starts serving it again.
     *
     * Declaring it on the class also settles the Refusable/Searchable collision —
     * a class method outranks both traits — so Movie needs no `insteadof`, while
     * Show, which still runs the trait's version, does.
     */
    public function shouldBeSearchable(): bool
    {
        return $this->tmdb_gone_at === null && ! $this->isRefused();
    }

    protected static function newFactory(): Factory
    {
        return MovieFactory::new();
    }

    /**
     * TMDB marks a movie-only promo record (a trailer or extra) with `video`;
     * /tv payloads carry no such key.
     *
     * @return list<string>
     */
    protected static function refusalColumns(): array
    {
        return [...self::REFUSAL_COLUMNS, '_tmdb_video'];
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
            '_tmdb_release_date' => NullableDate::class,
            '_tmdb_runtime' => 'integer',
            '_tmdb_budget' => 'integer',
            '_tmdb_revenue' => 'integer',
            '_tmdb_popularity' => 'float',
            '_tmdb_vote_average' => 'float',
            '_tmdb_vote_count' => 'integer',
            '_tmdb_video' => 'boolean',
            '_tmdb_adult' => 'boolean',
            '_tmdb_softcore' => 'boolean',
            '_tmdb_genres' => 'array',
            '_tmdb_origin_country' => 'array',
            '_tmdb_production_companies' => 'array',
            '_tmdb_production_countries' => 'array',
            '_tmdb_spoken_languages' => 'array',
            '_tmdb_belongs_to_collection' => 'array',
            '_tmdb_release_dates' => 'array',
            'tmdb_synced_at' => 'datetime',
            'tmdb_gone_at' => 'datetime',
        ];
    }
}
