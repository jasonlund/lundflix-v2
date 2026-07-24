<?php

declare(strict_types=1);

namespace App\Domains\PlexLibrary\Actions;

use App\Domains\PlexLibrary\Models\PlexLibrary;
use App\Domains\PlexLibrary\Models\PlexMovie;
use App\Domains\PlexLibrary\Models\PlexServer;
use App\Domains\PlexLibrary\Support\PlexGuids;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Date;

final class ReconcilePlexMovies
{
    /**
     * @param  array<int, array<string, mixed>>  $items  decoded Plex "section all" Metadata items
     */
    public function handle(PlexServer $server, PlexLibrary $library, array $items): int
    {
        $now = now();

        $rows = array_map(
            fn (array $item): array => $this->rawRow($server, $library, $item, $now),
            $items,
        );

        if ($rows !== []) {
            PlexMovie::upsert($rows, ['plex_server_id', '_plex_ratingKey'], array_keys($rows[0]));
        }

        // Prune this library's rows that were absent from the payload. Reuse the
        // ratingKeys the upsert rows already cast rather than re-deriving them; an
        // empty payload leaves whereNotIn([]), a full-clear reconcile of the scope.
        PlexMovie::query()
            ->where('plex_server_id', $server->id)
            ->where('plex_library_id', $library->id)
            ->whereNotIn('_plex_ratingKey', array_column($rows, '_plex_ratingKey'))
            ->delete();

        return count($rows);
    }

    /**
     * Build a cast-bypassing row for `Model::upsert()`: the raw Guid list is
     * pre-encoded and epoch timestamps are rendered to datetime strings, since
     * `upsert()` writes raw values without invoking the model's casts.
     *
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function rawRow(PlexServer $server, PlexLibrary $library, array $item, Carbon $now): array
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
            '_plex_addedAt' => $this->toDateTime($item['addedAt'] ?? null),
            '_plex_updatedAt' => $this->toDateTime($item['updatedAt'] ?? null),
            '_plex_guids' => json_encode($item['Guid'] ?? []),
            'synced_at' => $now->toDateTimeString(),
        ];
    }

    private function toDateTime(?int $epoch): ?string
    {
        return $epoch === null ? null : Date::createFromTimestamp($epoch)->toDateTimeString();
    }
}
