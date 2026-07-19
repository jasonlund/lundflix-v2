<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Actions;

use App\Domains\Catalog\Models\Show;
use App\Domains\Catalog\Support\RawSourceColumns;
use App\Domains\Catalog\Support\TvdbCrosswalk;
use Illuminate\Support\Carbon;

final readonly class UpsertTvdbShows
{
    private const string SOURCE = 'tvdb';

    /**
     * Raw TVDB payload keys mapped 1:1 onto `_tvdb_*` columns, value taken raw.
     *
     * @var list<string>
     */
    private const array RAW_COLUMNS = [
        'id', 'name', 'slug', 'overview', 'score', 'firstAired', 'lastAired',
        'year', 'averageRuntime', 'status', 'originalLanguage', 'originalCountry',
        'genres', 'remoteIds',
    ];

    /**
     * TVDB `_tvdb_*` columns cast to `array` on the model; when writing via the
     * cast-bypassing `Model::upsert()` these must be passed already json-encoded.
     *
     * @var list<string>
     */
    private const array JSON_COLUMNS = [
        '_tvdb_status',
        '_tvdb_genres',
        '_tvdb_remoteIds',
    ];

    /**
     * TVDB `_tvdb_*` DATE columns cast via `NullableDate` on the model; when
     * writing through the cast-bypassing `Model::upsert()` that cast never runs,
     * so blank/sentinel dates must be nulled here to satisfy MySQL's strict DATE
     * columns (matching `NullableDate::set()`).
     *
     * @var list<string>
     */
    private const array DATE_COLUMNS = [
        '_tvdb_firstAired',
        '_tvdb_lastAired',
    ];

    /**
     * @param  array<int, array<string, mixed>>  $payloads  decoded TVDB /series/{id}/extended responses
     */
    public function handle(array $payloads): int
    {
        if ($payloads === []) {
            return 0;
        }

        $now = now();

        $rows = array_map(fn (array $payload): array => $this->rawTvdbRow($payload, $now), $payloads);

        $touchedIds = $this->upsertByTvdbId($this->nullAmbiguousTmdbIds($rows));

        Show::query()->whereIn('id', $touchedIds)->searchable();

        return count($payloads);
    }

    /**
     * Null `_tmdb_id` on every row that shares it with another row in the batch:
     * dirty source data can repeat a cross-id, and the unique `_tmdb_id` column
     * would otherwise reject the whole batch while a fabricated crosswalk would
     * false-merge two distinct shows. Nobody claims an ambiguous crosswalk.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function nullAmbiguousTmdbIds(array $rows): array
    {
        $counts = array_count_values(array_filter(array_column($rows, '_tmdb_id')));

        return array_map(static function (array $row) use ($counts): array {
            if (($counts[$row['_tmdb_id']] ?? 0) > 1) {
                $row['_tmdb_id'] = null;
            }

            return $row;
        }, $rows);
    }

    /**
     * Upsert the raw rows by `_tvdb_id` via a cast-bypassing `Model::upsert()`,
     * then return the ids of the affected shows (so they can be reindexed).
     * Returns no ids when there are no rows.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return list<int|string>
     */
    private function upsertByTvdbId(array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        Show::upsert($rows, ['_tvdb_id'], array_keys($rows[0]));

        return Show::query()
            ->whereIn('_tvdb_id', array_column($rows, '_tvdb_id'))
            ->pluck('id')
            ->all();
    }

    /**
     * Map a raw TVDB payload onto the model's source-prefixed `_tvdb_*` columns
     * (plus the app-owned `tvdb_synced_at` stamp), persisting each value exactly
     * as the API returned it. The `_imdb_id` crosswalk is seeded separately in
     * {@see rawTvdbRow()}, not here.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function tvdbColumnsFor(array $payload, Carbon $now): array
    {
        return [
            ...RawSourceColumns::map(self::SOURCE, self::RAW_COLUMNS, $payload),
            'tvdb_synced_at' => $now,
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
    private function rawTvdbRow(array $payload, Carbon $now): array
    {
        $row = $this->tvdbColumnsFor($payload, $now);

        // Seed `_imdb_id`/`_tmdb_id` from remoteIds[] on the `_tvdb_id` upsert so a
        // later per-source sync matches this row instead of duplicating it.
        $row = [...$row, ...TvdbCrosswalk::normalize($payload['remoteIds'] ?? null)];

        foreach (self::JSON_COLUMNS as $column) {
            $row[$column] = $row[$column] === null ? null : json_encode($row[$column]);
        }

        foreach (self::DATE_COLUMNS as $column) {
            if (in_array($row[$column], [null, '', '0000-00-00'], true)) {
                $row[$column] = null;
            }
        }

        $row['tvdb_synced_at'] = $now->toDateTimeString();

        return $row;
    }
}
