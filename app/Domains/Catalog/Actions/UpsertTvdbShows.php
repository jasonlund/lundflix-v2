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

        $rows = array_map(
            fn (array $payload): array => $this->rawTvdbRow($payload, $now),
            $payloads,
        );

        Show::upsert($rows, ['_tvdb_id'], array_keys($rows[0]));

        Show::query()
            ->whereIn('_tvdb_id', array_column($rows, '_tvdb_id'))
            ->searchable();

        return count($payloads);
    }

    /**
     * Pull the IMDb id out of the nested `remoteIds[]`: the entry whose
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
     * pre-encoded and the timestamp is rendered to a datetime string, since
     * `upsert()` writes raw values without invoking the model's casts.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function rawTvdbRow(array $payload, Carbon $now): array
    {
        $row = $this->tvdbColumnsFor($payload, $now);

        // TVDB carries IMDb's identity key in remoteIds[]; copy it raw so the
        // `_tvdb_id` upsert also seeds `_imdb_id` (null when absent).
        $row['_imdb_id'] = $this->imdbIdFrom($payload);

        foreach (self::JSON_COLUMNS as $column) {
            $row[$column] = $row[$column] === null ? null : json_encode($row[$column]);
        }

        $row['tvdb_synced_at'] = $now->toDateTimeString();

        return $row;
    }
}
