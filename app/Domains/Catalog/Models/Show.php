<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Models;

use App\Domains\Catalog\Casts\ImdbGenres;
use App\Domains\Catalog\Database\Factories\ShowFactory;
use App\Domains\Catalog\Enums\TitleType;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Laravel\Scout\Searchable;

class Show extends Model
{
    /** @use HasFactory<ShowFactory> */
    use HasFactory;

    use Searchable;

    /**
     * @return array<string, mixed>
     */
    public function toSearchableArray(): array
    {
        return [
            'id' => $this->id,
            'imdb_id' => $this->_imdb_id,
            'title' => $this->_imdb_primary_title,
            'start_year' => $this->_imdb_start_year,
            'num_votes' => $this->_imdb_num_votes,
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
            '_imdb_start_year' => 'integer',
            '_imdb_end_year' => 'integer',
            '_imdb_runtime_minutes' => 'integer',
            '_imdb_num_votes' => 'integer',
            '_imdb_average_rating' => 'float',
            '_imdb_genres' => ImdbGenres::class,
            '_imdb_title_type' => TitleType::class,
        ];
    }
}
