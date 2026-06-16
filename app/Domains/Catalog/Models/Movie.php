<?php

namespace App\Domains\Catalog\Models;

use App\Domains\Catalog\Database\Factories\MovieFactory;
use App\Domains\Catalog\Enums\Genre;
use App\Domains\Catalog\Enums\TitleType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\AsEnumCollection;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Laravel\Scout\Searchable;

#[Fillable(['imdb_id', 'title', 'title_type', 'year', 'runtime', 'genres', 'num_votes', 'average_rating'])]
class Movie extends Model
{
    /** @use HasFactory<MovieFactory> */
    use HasFactory;

    use Searchable;

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
    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'runtime' => 'integer',
            'num_votes' => 'integer',
            'average_rating' => 'float',
            'genres' => AsEnumCollection::of(Genre::class),
            'title_type' => TitleType::class,
        ];
    }
}
