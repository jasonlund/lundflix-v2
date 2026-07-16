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
     * raw. TMDB tv has no top-level `imdb_id`; the IMDb id lives raw inside
     * `_tmdb_external_ids`.
     *
     * @var list<string>
     */
    private const array RAW_COLUMNS = [
        'id', 'name', 'original_name', 'original_language',
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
     * TMDB `_tmdb_*` DATE columns cast via `NullableDate` on the model; when
     * writing through the cast-bypassing `Model::upsert()` that cast never runs,
     * so blank/sentinel dates must be nulled here to satisfy MySQL's strict DATE
     * columns (matching {@see NullableDate::set()}).
     *
     * @var list<string>
     */
    private const array DATE_COLUMNS = [
        '_tmdb_first_air_date',
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

        $rows = array_map(
            fn (array $payload): array => $this->rawTmdbRow($payload, $now),
            $payloads,
        );

        Show::upsert($rows, ['_tmdb_id'], array_keys($rows[0]));

        Show::query()
            ->whereIn('_tmdb_id', array_column($rows, '_tmdb_id'))
            ->searchable();

        return count($payloads);
    }

    /**
     * Map a raw TMDB payload onto the model's source-prefixed `_tmdb_*` columns
     * (plus the app-owned `tmdb_synced_at` stamp), persisting each value exactly
     * as the API returned it. TVDB-owned `_imdb_id` / `_tvdb_id` are never touched.
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
     * pre-encoded, sentinel dates nulled, and the timestamp rendered to a
     * datetime string, since `upsert()` writes raw values without invoking the
     * model's casts.
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

        foreach (self::DATE_COLUMNS as $column) {
            if (in_array($row[$column], [null, '', '0000-00-00'], true)) {
                $row[$column] = null;
            }
        }

        $row['tmdb_synced_at'] = $now->toDateTimeString();

        return $row;
    }
}
