<?php

declare(strict_types=1);

namespace App\Domains\PlexLibrary\Actions;

use App\Domains\PlexLibrary\Actions\Concerns\MarksAndSweepsPlexRows;
use App\Domains\PlexLibrary\Models\PlexLibrary;
use App\Domains\PlexLibrary\Models\PlexMovie;
use App\Domains\PlexLibrary\Models\PlexServer;
use App\Domains\PlexLibrary\Support\PlexGuids;
use App\Domains\PlexLibrary\Support\PlexTimestamp;
use Carbon\CarbonInterface;

final class ReconcilePlexMovies
{
    use MarksAndSweepsPlexRows;

    /**
     * @return class-string<PlexMovie>
     */
    protected static function model(): string
    {
        return PlexMovie::class;
    }

    /**
     * Build a cast-bypassing row for `Model::upsert()`: the raw Guid list is
     * pre-encoded and epoch timestamps are rendered to datetime strings, since
     * `upsert()` writes raw values without invoking the model's casts.
     *
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    protected function rowFor(PlexServer $server, PlexLibrary $library, array $item, CarbonInterface $now): array
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
