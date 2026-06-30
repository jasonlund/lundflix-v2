<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Actions;

use App\Domains\Catalog\Models\Show;
use App\Domains\Catalog\Support\RawSourceColumns;
use Illuminate\Support\Carbon;

final class UpsertTmdbShows
{
    private const string SOURCE = 'tmdb';

    /**
     * Raw TMDB /tv payload keys mapped 1:1 onto `_tmdb_*` columns, value taken
     * raw. TMDB tv has no top-level `imdb_id`, so `_tmdb_imdb_id` maps to null;
     * the IMDb id lives raw inside `_tmdb_external_ids`.
     *
     * @var list<string>
     */
    private const array RAW_COLUMNS = [
        'id', 'imdb_id', 'name', 'original_name', 'original_language',
        'overview', 'tagline', 'status', 'first_air_date', 'popularity',
        'vote_average', 'vote_count', 'genres', 'poster_path', 'backdrop_path',
        'external_ids',
    ];

    /**
     * TMDB `_tmdb_*` columns cast to `array` on the model; when writing via the
     * cast-bypassing `Model::upsert()` these must be passed already json-encoded.
     *
     * @var list<string>
     */
    private const array JSON_COLUMNS = [
        '_tmdb_genres',
        '_tmdb_external_ids',
    ];

    /**
     * @param  array<int, array<string, mixed>>  $payloads  decoded TMDB /tv responses
     */
    public function handle(array $payloads): int
    {
        if ($payloads === []) {
            return 0;
        }

        $now = now();

        $imdbIds = array_values(array_filter(array_map(
            static fn (array $payload): ?string => $payload['external_ids']['imdb_id'] ?? null,
            $payloads,
        )));

        $existingByImdbId = $imdbIds === []
            ? collect()
            : Show::query()->whereIn('imdb_id', $imdbIds)->get()->keyBy('imdb_id');

        $touchedIds = [];
        $tmdbOnlyRows = [];

        foreach ($payloads as $payload) {
            $imdbId = $payload['external_ids']['imdb_id'] ?? null;
            $existing = $imdbId === null ? null : $existingByImdbId->get($imdbId);

            if ($existing instanceof Show) {
                $existing->fill($this->tmdbColumnsFor($payload, $now));
                $existing->save();
                $touchedIds[] = $existing->getKey();

                continue;
            }

            $tmdbOnlyRows[] = $this->rawTmdbRow($payload, $now);
        }

        $touchedIds = array_merge($touchedIds, $this->insertTmdbOnly($tmdbOnlyRows));

        Show::query()->whereIn('id', $touchedIds)->searchable();

        return count($payloads);
    }

    /**
     * Insert the payloads that matched no existing IMDb row via a cast-bypassing
     * `Model::upsert()`, then return the ids of the affected shows (so they can
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

        Show::upsert($rows, ['_tmdb_id'], array_keys($rows[0]));

        return Show::query()
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
