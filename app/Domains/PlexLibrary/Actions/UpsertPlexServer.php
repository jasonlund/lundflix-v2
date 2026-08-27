<?php

declare(strict_types=1);

namespace App\Domains\PlexLibrary\Actions;

use App\Domains\PlexLibrary\Models\PlexServer;

final readonly class UpsertPlexServer
{
    /**
     * @param  array<string, mixed>  $connection
     */
    public function handle(array $connection): ?PlexServer
    {
        return PlexServer::updateOrCreate(
            ['_plex_clientIdentifier' => $connection['clientIdentifier']],
            [
                '_plex_name' => $connection['name'],
                'uri' => $connection['uri'],
                'synced_at' => now(),
            ],
        );
    }
}
