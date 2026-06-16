<?php

namespace App\Domains\Catalog\Actions;

use App\Domains\Catalog\Enums\Genre;
use App\Domains\Catalog\Models\Show;

final class UpsertShows
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
            'start_year' => $row['startYear'],
            'end_year' => $row['endYear'],
            'runtime' => $row['runtimeMinutes'],
            'genres' => json_encode(Genre::knownValues($row['genres'] ?? [])),
        ], $rows);

        Show::upsert($payloads, ['imdb_id'], ['title', 'title_type', 'start_year', 'end_year', 'runtime', 'genres']);

        $imdbIds = array_column($payloads, 'imdb_id');

        Show::whereIn('imdb_id', $imdbIds)->searchable();

        return count($payloads);
    }
}
