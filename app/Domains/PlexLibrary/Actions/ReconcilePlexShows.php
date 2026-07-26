<?php

declare(strict_types=1);

namespace App\Domains\PlexLibrary\Actions;

use App\Domains\PlexLibrary\Models\PlexLibrary;
use App\Domains\PlexLibrary\Models\PlexServer;
use App\Domains\PlexLibrary\Models\PlexShow;
use App\Domains\PlexLibrary\Support\PlexGuids;
use App\Domains\PlexLibrary\Support\PlexTimestamp;
use Carbon\CarbonInterface;

final class ReconcilePlexShows
{
    /**
     * @param  array<int, array<string, mixed>>  $metadata
     * @return array<int, array{_plex_ratingKey: string, id: int}>
     */
    public function handle(PlexServer $server, PlexLibrary $library, array $metadata): array
    {
        $now = now();

        $rows = array_map(
            fn (array $item): array => $this->rowFor($server, $library, $item, $now),
            $metadata,
        );

        $incomingRatingKeys = array_column($rows, '_plex_ratingKey');

        // Diff BEFORE the write: the upsert overwrites the very
        // `_plex_updatedAt`/`_plex_leafCount` we compare on, so a post-write read
        // could never tell a moved show apart from an unchanged one.
        $changedRatingKeys = $this->changedRatingKeys($server, $rows);

        $this->upsertAndPrune($server, $library, $rows, $incomingRatingKeys);

        // Resolve ids only now, AFTER the write: a newly inserted show has no id
        // until the upsert creates its row.
        return $this->resolveChanged($server, $changedRatingKeys);
    }

    /**
     * Rating keys whose incoming `_plex_updatedAt`/`_plex_leafCount` differ from
     * the stored row (or that are new). Snapshots the stored values, so it must
     * run before {@see upsertAndPrune()} overwrites them.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return list<string>
     */
    private function changedRatingKeys(PlexServer $server, array $rows): array
    {
        $stored = PlexShow::query()
            ->where('plex_server_id', $server->id)
            ->whereIn('_plex_ratingKey', array_column($rows, '_plex_ratingKey'))
            ->get(['_plex_ratingKey', '_plex_updatedAt', '_plex_leafCount'])
            ->keyBy('_plex_ratingKey');

        return collect($rows)
            ->filter(fn (array $row): bool => $this->isChanged($row, $stored->get($row['_plex_ratingKey'])))
            ->pluck('_plex_ratingKey')
            ->all();
    }

    /**
     * Upsert the incoming rows and prune shows that vanished from this server's
     * library.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @param  list<string>  $incomingRatingKeys
     */
    private function upsertAndPrune(PlexServer $server, PlexLibrary $library, array $rows, array $incomingRatingKeys): void
    {
        if ($rows !== []) {
            $updateColumns = array_values(array_diff(
                array_keys($rows[0]),
                ['plex_server_id', '_plex_ratingKey'],
            ));

            PlexShow::upsert($rows, ['plex_server_id', '_plex_ratingKey'], $updateColumns);
        }

        // The payload only speaks for this server's library, so the prune is scoped
        // to both: server because `_plex_ratingKey` is unique only within a server,
        // and library so reconciling one never deletes a sibling library's shows.
        PlexShow::query()
            ->where('plex_server_id', $server->id)
            ->where('plex_library_id', $library->id)
            ->whereNotIn('_plex_ratingKey', $incomingRatingKeys)
            ->delete();
    }

    /**
     * Resolve the changed rating keys to `{_plex_ratingKey, id}` pairs, reading
     * back the ids the upsert assigned to newly inserted shows.
     *
     * @param  list<string>  $changedRatingKeys
     * @return array<int, array{_plex_ratingKey: string, id: int}>
     */
    private function resolveChanged(PlexServer $server, array $changedRatingKeys): array
    {
        return PlexShow::query()
            ->where('plex_server_id', $server->id)
            ->whereIn('_plex_ratingKey', $changedRatingKeys)
            ->get(['id', '_plex_ratingKey'])
            ->map(fn (PlexShow $show): array => [
                '_plex_ratingKey' => $show->_plex_ratingKey,
                'id' => $show->id,
            ])
            ->all();
    }

    /**
     * A show changed if it is newly inserted (no snapshot) or its incoming
     * `_plex_updatedAt`/`_plex_leafCount` differs from the stored value. Both
     * sides are rendered to a common representation so a format mismatch cannot
     * make an unchanged show look moved (or vice versa).
     *
     * @param  array<string, mixed>  $row
     */
    private function isChanged(array $row, ?PlexShow $stored): bool
    {
        if (! $stored instanceof PlexShow) {
            return true;
        }

        $updatedAtMoved = $row['_plex_updatedAt'] !== $stored->_plex_updatedAt?->toDateTimeString();
        $leafCountMoved = (int) $row['_plex_leafCount'] !== (int) $stored->_plex_leafCount;

        return $updatedAtMoved || $leafCountMoved;
    }

    /**
     * Map one Plex Metadata item onto the `plex_shows` columns: source-prefixed
     * `_plex_*` facts stored raw, the Guid[] crosswalk normalized into queryable
     * `_imdb_id`/`_tmdb_id`/`_tvdb_id` ids, scoped to the given server/library.
     *
     * Values are pre-rendered for the cast-bypassing `Model::upsert()`: the
     * `_plex_guids` array is json-encoded and the `_plex_addedAt`/`_plex_updatedAt`
     * timestamps are rendered to datetime strings, since `upsert()` never runs the
     * model's casts.
     *
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function rowFor(PlexServer $server, PlexLibrary $library, array $item, CarbonInterface $now): array
    {
        $ids = PlexGuids::extract($item);

        return [
            'plex_server_id' => $server->id,
            'plex_library_id' => $library->id,
            '_plex_ratingKey' => (string) $item['ratingKey'],
            '_plex_guid' => $item['guid'],
            '_plex_guids' => isset($item['Guid']) ? json_encode($item['Guid']) : null,
            '_plex_title' => $item['title'],
            '_plex_year' => $item['year'] ?? null,
            '_plex_leafCount' => $item['leafCount'] ?? null,
            '_plex_childCount' => $item['childCount'] ?? null,
            '_plex_addedAt' => PlexTimestamp::fromEpoch($item['addedAt'] ?? null)?->toDateTimeString(),
            '_plex_updatedAt' => PlexTimestamp::fromEpoch($item['updatedAt'] ?? null)?->toDateTimeString(),
            '_imdb_id' => $ids['imdb'],
            '_tmdb_id' => $ids['tmdb'],
            '_tvdb_id' => $ids['tvdb'],
            'synced_at' => $now->toDateTimeString(),
        ];
    }
}
