<?php

declare(strict_types=1);

namespace App\Domains\PlexLibrary\Actions;

use App\Domains\PlexLibrary\Models\PlexLibrary;
use App\Domains\PlexLibrary\Models\PlexMovie;
use App\Domains\PlexLibrary\Models\PlexServer;
use App\Domains\PlexLibrary\Support\PlexGuids;
use App\Domains\PlexLibrary\Support\PlexTimestamp;
use Illuminate\Support\Carbon;

final class ReconcilePlexMovies
{
    /**
     * @param  array<int, array<string, mixed>>  $items  decoded Plex "section all" Metadata items
     */
    public function handle(PlexServer $server, PlexLibrary $library, array $items): int
    {
        $now = now();

        $rows = array_map(
            fn (array $item): array => $this->rowFor($server, $library, $item, $now),
            $items,
        );

        $this->upsertAndPrune($server, $library, $rows);

        return count($rows);
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function upsertAndPrune(PlexServer $server, PlexLibrary $library, array $rows): void
    {
        if ($rows !== []) {
            $updateColumns = array_values(array_diff(
                array_keys($rows[0]),
                ['plex_server_id', '_plex_ratingKey'],
            ));

            PlexMovie::upsert($rows, ['plex_server_id', '_plex_ratingKey'], $updateColumns);
        }

        // The payload only speaks for this server's library, so the prune is scoped
        // to both: server because `_plex_ratingKey` is unique only within a server,
        // and library so reconciling one never deletes a sibling library's movies.
        // An empty payload leaves whereNotIn([]) — a full clear of that scope.
        PlexMovie::query()
            ->where('plex_server_id', $server->id)
            ->where('plex_library_id', $library->id)
            ->whereNotIn('_plex_ratingKey', array_column($rows, '_plex_ratingKey'))
            ->delete();
    }

    /**
     * Build a cast-bypassing row for `Model::upsert()`: the raw Guid list is
     * pre-encoded and epoch timestamps are rendered to datetime strings, since
     * `upsert()` writes raw values without invoking the model's casts.
     *
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function rowFor(PlexServer $server, PlexLibrary $library, array $item, Carbon $now): array
    {
        $ids = PlexGuids::extract($item);

        return [
            'plex_server_id' => $server->id,
            'plex_library_id' => $library->id,
            '_plex_ratingKey' => (string) $item['ratingKey'],
            '_imdb_id' => $ids['imdb'],
            '_tmdb_id' => $ids['tmdb'],
            '_tvdb_id' => $ids['tvdb'],
            '_plex_guid' => $item['guid'],
            '_plex_title' => $item['title'],
            '_plex_year' => $item['year'] ?? null,
            '_plex_addedAt' => PlexTimestamp::fromEpoch($item['addedAt'] ?? null)?->toDateTimeString(),
            '_plex_updatedAt' => PlexTimestamp::fromEpoch($item['updatedAt'] ?? null)?->toDateTimeString(),
            '_plex_guids' => json_encode($item['Guid'] ?? []),
            'synced_at' => $now->toDateTimeString(),
        ];
    }
}
