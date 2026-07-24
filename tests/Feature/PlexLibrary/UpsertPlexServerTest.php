<?php

declare(strict_types=1);

use App\Domains\PlexLibrary\Actions\UpsertPlexServer;
use App\Domains\PlexLibrary\Models\PlexServer;

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function plexConnection(array $overrides = []): array
{
    return [
        'clientIdentifier' => 'abc-123',
        'name' => 'Home Server',
        'uri' => 'https://192-168-1-10.plex.direct:32400',
        ...$overrides,
    ];
}

it('inserts one row for an unseen clientIdentifier and returns a PlexServer', function (): void {
    // Arrange
    $connection = plexConnection();

    // Act
    $result = (new UpsertPlexServer)->handle($connection);

    // Assert
    $this->assertDatabaseCount('plex_servers', 1);
    expect($result)->toBeInstanceOf(PlexServer::class);
});

it('updates the existing row in place when the same clientIdentifier is re-handled', function (): void {
    // Arrange
    (new UpsertPlexServer)->handle(plexConnection(['name' => 'Old Name', 'uri' => 'https://old.plex.direct:32400']));

    // Act
    (new UpsertPlexServer)->handle(plexConnection(['name' => 'New Name', 'uri' => 'https://new.plex.direct:32400']));

    // Assert
    $this->assertDatabaseCount('plex_servers', 1);
    $this->assertDatabaseHas('plex_servers', [
        '_plex_clientIdentifier' => 'abc-123',
        '_plex_name' => 'New Name',
        'uri' => 'https://new.plex.direct:32400',
    ]);
});

it('stamps synced_at on the returned row', function (): void {
    // Arrange
    $connection = plexConnection();

    // Act
    $result = (new UpsertPlexServer)->handle($connection);

    // Assert
    expect($result->synced_at)->not->toBeNull();
});
