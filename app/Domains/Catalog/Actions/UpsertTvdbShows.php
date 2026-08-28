<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Actions;

use App\Domains\Catalog\Models\Show;
use App\Domains\Catalog\Support\RawSourceColumns;
use App\Domains\Catalog\Support\TvdbCrosswalk;
use App\Domains\Common\Support\SourceId;
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
        'defaultSeasonType', 'genres', 'remoteIds',
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

        $this->upsertByTvdbId($this->nullExistingTmdbConflicts($this->nullAmbiguousTmdbIds($this->withValidTvdbId($rows))));

        return count($payloads);
    }

    /**
     * Drop any row whose `_tvdb_id` normalized to null: `_tvdb_id` is the
     * `Show::upsert()` conflict key, so a row without a valid native id has no
     * primary identity and can't be upserted — writing it would produce a
     * null-keyed row rather than merge, so it's filtered out of the batch.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function withValidTvdbId(array $rows): array
    {
        return array_values(array_filter(
            $rows,
            static fn (array $row): bool => $row['_tvdb_id'] !== null,
        ));
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
     * Null `_tmdb_id` on every batch row whose crosswalk already belongs to a
     * *different* existing show: `_tmdb_id` is a unique column, so a row upserting
     * by `_tvdb_id` must not overwrite/steal a crosswalk another `_tvdb_id` already
     * owns (the DB would reject the whole batch). A row whose `_tmdb_id` maps back
     * to its own `_tvdb_id` is a genuine idempotent re-seed and keeps it. Nobody
     * claims an ambiguous crosswalk.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function nullExistingTmdbConflicts(array $rows): array
    {
        $ids = array_values(array_filter(array_column($rows, '_tmdb_id')));

        if ($ids === []) {
            return array_values($rows);
        }

        $owners = Show::query()->whereIn('_tmdb_id', $ids)->pluck('_tvdb_id', '_tmdb_id');

        return array_map(static function (array $row) use ($owners): array {
            $tmdbId = $row['_tmdb_id'];

            if ($tmdbId !== null && $owners->has($tmdbId) && (int) $owners->get($tmdbId) !== (int) $row['_tvdb_id']) {
                $row['_tmdb_id'] = null;
            }

            return $row;
        }, $rows);
    }

    /**
     * Upsert the raw rows by `_tvdb_id` via a cast-bypassing `Model::upsert()`.
     * An empty batch short-circuits, so `upsert()` is never handed no rows.
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function upsertByTvdbId(array $rows): void
    {
        if ($rows === []) {
            return;
        }

        Show::upsert($rows, ['_tvdb_id'], array_keys($rows[0]));
    }

    /**
     * Map a raw TVDB payload onto the model's source-prefixed `_tvdb_*` columns
     * (plus the app-owned `tvdb_synced_at` stamp), persisting each value exactly
     * as the API returned it. The `_imdb_id` crosswalk is seeded separately in
     * {@see rawTvdbRow()}, not here.
     *
     * `_tvdb_id` is the one exception to "value taken raw": as the `upsert()`
     * conflict key it must be a clean queryable id, so the raw native `id` routes
     * through {@see SourceId::positiveInt()} (a malformed/oversized id becomes null
     * and the row is later dropped in {@see withValidTvdbId()}).
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function tvdbColumnsFor(array $payload, Carbon $now): array
    {
        return [
            ...RawSourceColumns::map(self::SOURCE, self::RAW_COLUMNS, $payload),
            '_tvdb_id' => SourceId::positiveInt($payload['id'] ?? null),
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
