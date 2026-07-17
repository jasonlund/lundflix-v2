<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Actions;

use App\Domains\Catalog\Models\Show;
use App\Domains\Catalog\Support\ExistingShowResolver;
use App\Domains\Catalog\Support\RawSourceColumns;
use App\Domains\Catalog\Support\SourceIds;
use Illuminate\Support\Carbon;

final readonly class UpsertTmdbShows
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
     * columns (matching `NullableDate::set()`).
     *
     * @var list<string>
     */
    private const array DATE_COLUMNS = [
        '_tmdb_first_air_date',
    ];

    public function __construct(private ExistingShowResolver $resolver = new ExistingShowResolver) {}

    /**
     * @param  array<int, array<string, mixed>>  $payloads  decoded TMDB /tv responses
     */
    public function handle(array $payloads): int
    {
        if ($payloads === []) {
            return 0;
        }

        $now = now();

        $payloads = $this->dedupeByImdbId($payloads);

        $candidates = $this->resolver->loadCandidates(array_map($this->sourceIdsFor(...), $payloads));

        $touchedIds = [];
        $tmdbOnlyRows = [];

        foreach ($payloads as $payload) {
            $existing = $this->resolver->match($this->sourceIdsFor($payload), $candidates);

            if ($existing instanceof Show) {
                $existing->fill($this->tmdbColumnsFor($payload, $now));
                $existing->save();
                $touchedIds[] = $existing->getKey();

                continue;
            }

            $tmdbOnlyRows[] = $this->rawTmdbRow($payload, $now);
        }

        $touchedIds = array_merge($touchedIds, $this->insertTmdbOnly($this->nullAmbiguousTvdbIds($tmdbOnlyRows)));

        Show::query()->whereIn('id', $touchedIds)->searchable();

        return count($payloads);
    }

    /**
     * Extract the three cross-source ids out of a TMDB /tv payload.
     *
     * @param  array<string, mixed>  $payload
     */
    private function sourceIdsFor(array $payload): SourceIds
    {
        return new SourceIds(
            $this->imdbIdFrom($payload),
            $this->tmdbIdFrom($payload),
            $this->tvdbIdFrom($payload),
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function imdbIdFrom(array $payload): ?string
    {
        return $payload['external_ids']['imdb_id'] ?? null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function tmdbIdFrom(array $payload): ?int
    {
        return isset($payload['id']) ? (int) $payload['id'] : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function tvdbIdFrom(array $payload): ?int
    {
        return isset($payload['external_ids']['tvdb_id'])
            ? (int) $payload['external_ids']['tvdb_id']
            : null;
    }

    /**
     * Collapse payloads that share an IMDb id down to the last one (last-wins),
     * so a single `imdb_id` is written exactly once per batch and a later payload
     * never leaves an earlier same-id write half-applied. Payloads with no IMDb id
     * are distinct tmdb-only shows and pass through untouched; matching them to an
     * existing row (including a prior source-only row, by any source id) is handled
     * downstream by {@see ExistingShowResolver}.
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
     * Null `_tvdb_id` on every row that shares it with another row in the batch:
     * dirty source data can repeat a cross-id, and the unique `_tvdb_id` column
     * would otherwise reject the whole batch while a fabricated crosswalk would
     * false-merge two distinct shows. Nobody claims an ambiguous crosswalk.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function nullAmbiguousTvdbIds(array $rows): array
    {
        $counts = array_count_values(array_filter(array_column($rows, '_tvdb_id')));

        return array_map(static function (array $row) use ($counts): array {
            if (($counts[$row['_tvdb_id']] ?? 0) > 1) {
                $row['_tvdb_id'] = null;
            }

            return $row;
        }, $rows);
    }

    /**
     * Insert the payloads that matched no existing show via a cast-bypassing
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

        // TMDB tv carries IMDb's identity key nested in external_ids; copy it raw
        // so the `_tmdb_id` upsert also seeds `_imdb_id` (null when absent).
        $row['_imdb_id'] = $this->imdbIdFrom($payload);

        // TMDB tv also carries TVDB's identity key in external_ids; seed the tvdb
        // crosswalk so a later TVDB run matches this row instead of duplicating it.
        $row['_tvdb_id'] = $this->tvdbIdFrom($payload);

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
