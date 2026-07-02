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
     * raw. The IMDb id lives raw inside `_tmdb_external_ids`.
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
     * @param  array<int, array<string, mixed>>  $payloads  decoded TMDB /tv responses
     */
    public function handle(array $payloads): int
    {
        if ($payloads === []) {
            return 0;
        }

        $now = now();

        $payloads = $this->dedupeByImdbId($payloads);

        $imdbIds = array_values(array_filter(array_map(
            static fn (array $payload): ?string => $payload['external_ids']['imdb_id'] ?? null,
            $payloads,
        )));

        $existingByImdbId = $imdbIds === []
            ? collect()
            : Show::query()->whereIn('_imdb_id', $imdbIds)->get()->keyBy('_imdb_id');

        $touchedIds = [];

        foreach ($payloads as $payload) {
            $imdbId = $payload['external_ids']['imdb_id'] ?? null;
            $existing = $imdbId === null ? null : $existingByImdbId->get($imdbId);

            if ($existing instanceof Show) {
                $existing->fill($this->tmdbColumnsFor($payload, $now));
                $existing->save();
                $touchedIds[] = $existing->getKey();
            }
        }

        Show::query()->whereIn('id', $touchedIds)->searchable();

        return count($payloads);
    }

    /**
     * Collapse payloads that share an IMDb id down to the last one (last-wins),
     * so a single `imdb_id` enriches its matching Show exactly once per batch and a
     * later payload never leaves an earlier same-id write half-applied. Payloads
     * with no IMDb id have nothing to match on and pass through untouched — they
     * simply enrich no existing row.
     *
     * @param  array<int, array<string, mixed>>  $payloads
     * @return list<array<string, mixed>>
     */
    private function dedupeByImdbId(array $payloads): array
    {
        $withoutImdbId = [];
        $byImdbId = [];

        foreach ($payloads as $payload) {
            $imdbId = $payload['external_ids']['imdb_id'] ?? null;

            if ($imdbId === null) {
                $withoutImdbId[] = $payload;

                continue;
            }

            $byImdbId[$imdbId] = $payload;
        }

        return array_values([...$withoutImdbId, ...$byImdbId]);
    }

    /**
     * Map a raw TMDB payload onto the model's source-prefixed `_tmdb_*` columns
     * (plus the app-owned `tmdb_synced_at` stamp), persisting each value exactly
     * as the API returned it. This action only enriches existing rows, so `_imdb_id`
     * and other IMDb-owned columns are never written.
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
}
