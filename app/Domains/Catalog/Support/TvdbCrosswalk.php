<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Support;

final class TvdbCrosswalk
{
    /**
     * TVDB carries the IMDb and TMDB identity keys inside its `remoteIds[]`, each
     * tagged by this exact `sourceName` string — the raw values the API returns.
     */
    private const string IMDB_SOURCE = 'IMDB';

    private const string TMDB_SOURCE = 'TheMovieDB.com';

    /**
     * Derive the normalized `_imdb_id`/`_tmdb_id` crosswalk pair from a TVDB
     * `remoteIds[]` list, so a later per-source sync matches this show instead of
     * duplicating it. Each raw id passes its matching {@see SourceId} guard, so a
     * malformed or absent entry becomes null.
     *
     * `$remoteIds` is `mixed` because upstream ships it malformed — a non-array
     * scalar coerces to no remoteIds (empty crosswalk) rather than a `TypeError`,
     * so the row still imports.
     *
     * @param  array<int, array<string, mixed>>|mixed  $remoteIds
     * @return array{_imdb_id: ?string, _tmdb_id: ?int}
     */
    public static function normalize(mixed $remoteIds): array
    {
        $remoteIds = is_array($remoteIds) ? $remoteIds : null;

        return [
            '_imdb_id' => SourceId::imdb(self::rawId($remoteIds, self::IMDB_SOURCE)),
            '_tmdb_id' => SourceId::tmdb(self::rawId($remoteIds, self::TMDB_SOURCE)),
        ];
    }

    /**
     * Pull the raw crosswalk `id` from the `remoteIds[]` entry whose `sourceName`
     * matches — first match wins, still unvalidated. Null when no entry matches.
     *
     * @param  array<int, array<string, mixed>>|null  $remoteIds
     */
    private static function rawId(?array $remoteIds, string $sourceName): mixed
    {
        foreach ($remoteIds ?? [] as $remoteId) {
            if (($remoteId['sourceName'] ?? null) === $sourceName) {
                return $remoteId['id'] ?? null;
            }
        }

        return null;
    }
}
