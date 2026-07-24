<?php

declare(strict_types=1);

namespace App\Domains\PlexLibrary\Actions;

use App\Domains\PlexLibrary\Models\PlexLibrary;
use App\Domains\PlexLibrary\Models\PlexServer;
use App\Domains\PlexLibrary\Support\PlexTimestamp;

final class ReconcilePlexLibraries
{
    /**
     * Section types the app mirrors — the audiovisual libraries. Music
     * ('artist'), photo, and other Plex section types are left untouched.
     *
     * @var list<string>
     */
    private const array AUDIOVISUAL_TYPES = ['movie', 'show'];

    /**
     * @param  array<int, array<string, mixed>>  $sections
     */
    public function handle(PlexServer $server, array $sections): int
    {
        $kept = collect($sections)
            ->filter(fn (array $section): bool => in_array($section['type'], self::AUDIOVISUAL_TYPES, true))
            ->each(function (array $section) use ($server): void {
                PlexLibrary::updateOrCreate(
                    ['plex_server_id' => $server->id, '_plex_key' => $section['key']],
                    [
                        '_plex_type' => $section['type'],
                        '_plex_title' => $section['title'],
                        '_plex_uuid' => $section['uuid'],
                        '_plex_updatedAt' => PlexTimestamp::fromEpoch($section['updatedAt']),
                        'synced_at' => now(),
                    ],
                );
            });

        $keepKeys = $kept->pluck('key')->all();

        // Prune this server's libraries absent from the incoming payload; the
        // DB-level ON DELETE CASCADE clears their child items. An empty keep-set
        // is intentional — no sections means every one of this server's
        // libraries is stale and gets pruned.
        PlexLibrary::query()
            ->where('plex_server_id', $server->id)
            ->whereNotIn('_plex_key', $keepKeys)
            ->delete();

        return $kept->count();
    }
}
