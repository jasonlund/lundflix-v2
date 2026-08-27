<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Actions;

use App\Domains\Catalog\Models\Movie;
use App\Domains\Catalog\Support\RawSourceColumns;
use App\Domains\Common\Support\SourceId;
use Illuminate\Support\Carbon;

final readonly class UpsertTmdbMovies
{
    private const string SOURCE = 'tmdb';

    /**
     * Raw TMDB payload keys mapped 1:1 onto `_tmdb_*` columns, value taken raw.
     *
     * @var list<string>
     */
    private const array RAW_COLUMNS = [
        'id', 'title', 'original_title', 'original_language',
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
     * TMDB `_tmdb_*` DATE columns cast via `NullableDate` on the model; when
     * writing through the cast-bypassing `Model::upsert()` that cast never runs,
     * so blank/sentinel dates must be nulled here to satisfy MySQL's strict DATE
     * columns (matching {@see NullableDate::set()}).
     *
     * @var list<string>
     */
    private const array DATE_COLUMNS = [
        '_tmdb_release_date',
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

        $rows = array_map(
            fn (array $payload): array => $this->rawTmdbRow($payload, $now),
            $payloads,
        );

        // The native `_tmdb_id` is the upsert conflict key; a payload whose id
        // normalized to null has no primary identity, so drop it rather than
        // writing a null-keyed row that SQL would reject.
        $rows = array_values(array_filter(
            $rows,
            fn (array $row): bool => $row['_tmdb_id'] !== null,
        ));

        if ($rows === []) {
            return 0;
        }

        Movie::upsert($rows, ['_tmdb_id'], array_keys($rows[0]));

        Movie::query()
            ->whereIn('_tmdb_id', array_column($rows, '_tmdb_id'))
            ->searchable();

        return count($rows);
    }

    /**
     * Map a raw TMDB payload onto the model's source-prefixed `_tmdb_*` columns
     * (plus the app-owned `tmdb_synced_at` stamp), persisting each value exactly
     * as the API returned it.
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

        // The native `_tmdb_id` is the upsert conflict key, so normalize the raw
        // payload id through `SourceId` (a malformed/oversized value → null),
        // keeping a bad native id from ever reaching SQL as the key.
        $row['_tmdb_id'] = SourceId::positiveInt($payload['id'] ?? null);

        // TMDB carries IMDb's identity key on its payload; validate it through
        // `SourceId` so the `_tmdb_id` upsert seeds a canonical `_imdb_id`
        // (malformed or absent values normalize to null).
        $row['_imdb_id'] = SourceId::imdb($payload['imdb_id'] ?? null);

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
