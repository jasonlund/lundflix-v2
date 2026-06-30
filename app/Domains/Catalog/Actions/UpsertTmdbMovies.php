<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Actions;

use App\Domains\Catalog\Models\Movie;
use App\Domains\Catalog\Support\RawSourceColumns;
use Illuminate\Support\Carbon;

final class UpsertTmdbMovies
{
    private const string SOURCE = 'tmdb';

    /**
     * Raw TMDB payload keys mapped 1:1 onto `_tmdb_*` columns, value taken raw.
     *
     * @var list<string>
     */
    private const array RAW_COLUMNS = [
        'id', 'imdb_id', 'title', 'original_title', 'original_language',
        'overview', 'tagline', 'homepage', 'status', 'release_date',
        'runtime', 'budget', 'revenue', 'popularity', 'vote_average',
        'vote_count', 'video', 'genres', 'origin_country', 'production_companies',
        'production_countries', 'spoken_languages', 'belongs_to_collection',
        'release_dates', 'poster_path', 'backdrop_path',
    ];

    /**
     * TMDB `_tmdb_*` columns cast to `array` on the model; when writing via the
     * cast-bypassing `Model::upsert()` these must be passed already json-encoded.
     *
     * @var list<string>
     */
    private const array JSON_COLUMNS = [
        '_tmdb_genres',
        '_tmdb_origin_country',
        '_tmdb_production_companies',
        '_tmdb_production_countries',
        '_tmdb_spoken_languages',
        '_tmdb_belongs_to_collection',
        '_tmdb_release_dates',
    ];

    /**
     * @param  array<int, array<string, mixed>>  $payloads  decoded TMDB /movie responses
     */
    public function handle(array $payloads): int
    {
        if ($payloads === []) {
            return 0;
        }

        $now = now();

        $imdbIds = array_values(array_filter(array_map(
            static fn (array $payload): ?string => $payload['imdb_id'] ?? null,
            $payloads,
        )));

        $existingByImdbId = $imdbIds === []
            ? collect()
            : Movie::query()->whereIn('_imdb_id', $imdbIds)->get()->keyBy('_imdb_id');

        $touchedIds = [];
        $tmdbOnlyRows = [];

        foreach ($payloads as $payload) {
            $imdbId = $payload['imdb_id'] ?? null;
            $existing = $imdbId === null ? null : $existingByImdbId->get($imdbId);

            if ($existing instanceof Movie) {
                $existing->fill($this->tmdbColumnsFor($payload, $now));
                $existing->save();
                $touchedIds[] = $existing->getKey();

                continue;
            }

            $tmdbOnlyRows[] = $this->rawTmdbRow($payload, $now);
        }

        $touchedIds = array_merge($touchedIds, $this->insertTmdbOnly($tmdbOnlyRows));

        Movie::query()->whereIn('id', $touchedIds)->searchable();

        return count($payloads);
    }

    /**
     * Insert the payloads that matched no existing IMDb row via a cast-bypassing
     * `Model::upsert()`, then return the ids of the affected movies (so they can
     * be reindexed). Returns no ids when there are no tmdb-only rows.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return list<int|string>
     */
    private function insertTmdbOnly(array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        Movie::upsert($rows, ['_tmdb_id'], array_keys($rows[0]));

        return Movie::query()
            ->whereIn('_tmdb_id', array_column($rows, '_tmdb_id'))
            ->pluck('id')
            ->all();
    }

    /**
     * Map a raw TMDB payload onto the model's source-prefixed `_tmdb_*` columns
     * (plus the app-owned `tmdb_synced_at` stamp), persisting each value exactly
     * as the API returned it. App-owned IMDb columns are never touched.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function tmdbColumnsFor(array $payload, Carbon $now): array
    {
        return [
            ...RawSourceColumns::map(self::SOURCE, self::RAW_COLUMNS, $payload),
            'tmdb_synced_at' => $now,
        ];
    }

    /**
     * Build a cast-bypassing row for `Model::upsert()`: json columns are
     * pre-encoded and the timestamp is rendered to a datetime string, since
     * `upsert()` writes raw values without invoking the model's casts.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function rawTmdbRow(array $payload, Carbon $now): array
    {
        $row = $this->tmdbColumnsFor($payload, $now);

        foreach (self::JSON_COLUMNS as $column) {
            $row[$column] = $row[$column] === null ? null : json_encode($row[$column]);
        }

        $row['tmdb_synced_at'] = $now->toDateTimeString();

        return $row;
    }
}
