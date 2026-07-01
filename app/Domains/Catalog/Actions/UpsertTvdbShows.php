<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Actions;

use App\Domains\Catalog\Models\Show;
use App\Domains\Catalog\Support\RawSourceColumns;
use Illuminate\Support\Carbon;

final class UpsertTvdbShows
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
     * @param  array<int, array<string, mixed>>  $payloads  decoded TVDB /series/{id}/extended responses
     */
    public function handle(array $payloads): int
    {
        if ($payloads === []) {
            return 0;
        }

        $now = now();

        $payloads = $this->dedupeByImdbId($payloads);

        $imdbIds = array_values(array_filter(array_map(
            $this->imdbIdFrom(...),
            $payloads,
        )));

        $existingByImdbId = $imdbIds === []
            ? collect()
            : Show::query()->whereIn('_imdb_id', $imdbIds)->get()->keyBy('_imdb_id');

        $touchedIds = [];
        $tvdbOnlyRows = [];

        foreach ($payloads as $payload) {
            $imdbId = $this->imdbIdFrom($payload);
            $existing = $imdbId === null ? null : $existingByImdbId->get($imdbId);

            if ($existing instanceof Show) {
                $existing->fill($this->tvdbColumnsFor($payload, $now));
                $existing->save();
                $touchedIds[] = $existing->getKey();

                continue;
            }

            $tvdbOnlyRows[] = $this->rawTvdbRow($payload, $now);
        }

        $touchedIds = array_merge($touchedIds, $this->insertTvdbOnly($tvdbOnlyRows));

        Show::query()->whereIn('id', $touchedIds)->searchable();

        return count($payloads);
    }

    /**
     * Pull the IMDb anchor out of the nested `remoteIds[]`: the entry whose
     * `sourceName` is "IMDB". Returns null when there is no such entry.
     *
     * @param  array<string, mixed>  $payload
     */
    private function imdbIdFrom(array $payload): ?string
    {
        foreach ($payload['remoteIds'] ?? [] as $remoteId) {
            if (($remoteId['sourceName'] ?? null) === 'IMDB') {
                return $remoteId['id'] ?? null;
            }
        }

        return null;
    }

    /**
     * Collapse payloads that share an IMDb id down to the last one (last-wins),
     * so a single `imdb_id` is written exactly once per batch and a later payload
     * never leaves an earlier same-id write half-applied. Payloads with no IMDb id
     * are distinct tvdb-only shows and pass through untouched. (Cross-batch dedup
     * of prior source-only rows by `_tvdb_id` is deferred to FLIX-180.)
     *
     * @param  array<int, array<string, mixed>>  $payloads
     * @return list<array<string, mixed>>
     */
    private function dedupeByImdbId(array $payloads): array
    {
        $withoutImdbId = [];
        $byImdbId = [];

        foreach ($payloads as $payload) {
            $imdbId = $this->imdbIdFrom($payload);

            if ($imdbId === null) {
                $withoutImdbId[] = $payload;

                continue;
            }

            $byImdbId[$imdbId] = $payload;
        }

        return array_values([...$withoutImdbId, ...$byImdbId]);
    }

    /**
     * Insert the payloads that matched no existing IMDb row via a cast-bypassing
     * `Model::upsert()`, then return the ids of the affected shows (so they can
     * be reindexed). Returns no ids when there are no tvdb-only rows.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return list<int|string>
     */
    private function insertTvdbOnly(array $rows): array
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
     * as the API returned it. App-owned IMDb columns are never touched.
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
     * pre-encoded and the timestamp is rendered to a datetime string, since
     * `upsert()` writes raw values without invoking the model's casts.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function rawTvdbRow(array $payload, Carbon $now): array
    {
        $row = $this->tvdbColumnsFor($payload, $now);

        foreach (self::JSON_COLUMNS as $column) {
            $row[$column] = $row[$column] === null ? null : json_encode($row[$column]);
        }

        $row['tvdb_synced_at'] = $now->toDateTimeString();

        return $row;
    }
}
