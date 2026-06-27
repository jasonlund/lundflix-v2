<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Actions;

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
            '_imdb_id' => $row['tconst'],
            '_imdb_primary_title' => $row['primaryTitle'],
            '_imdb_title_type' => $row['titleType'],
            '_imdb_start_year' => $row['startYear'],
            '_imdb_runtime_minutes' => $row['runtimeMinutes'],
            '_imdb_genres' => $row['genres'] === null ? null : json_encode($row['genres']),
        ], $rows);

        Movie::upsert($payloads, ['_imdb_id'], ['_imdb_primary_title', '_imdb_title_type', '_imdb_start_year', '_imdb_runtime_minutes', '_imdb_genres']);

        $imdbIds = array_column($payloads, '_imdb_id');

        Movie::whereIn('_imdb_id', $imdbIds)->searchable();

        return count($payloads);
    }
}
