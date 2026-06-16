<?php

namespace App\Domains\Catalog\Actions;

use App\Domains\Catalog\Enums\Genre;
use App\Domains\Catalog\Models\Movie;

final class UpsertMovies
{
    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    public function handle(array $rows): int
    {
        if ($rows === []) {
            return 0;
        }

        $payloads = array_map(fn (array $row): array => [
            'imdb_id' => $row['tconst'],
            'title' => $row['primaryTitle'],
            'title_type' => $row['titleType'],
            'year' => $row['startYear'],
            'runtime' => $row['runtimeMinutes'],
            'genres' => json_encode(Genre::knownValues($row['genres'] ?? [])),
        ], $rows);

        Movie::upsert($payloads, ['imdb_id'], ['title', 'title_type', 'year', 'runtime', 'genres']);

        $imdbIds = array_column($payloads, 'imdb_id');

        Movie::whereIn('imdb_id', $imdbIds)->searchable();

        return count($payloads);
    }
}
