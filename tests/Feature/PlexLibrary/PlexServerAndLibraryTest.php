<?php

declare(strict_types=1);

use App\Domains\PlexLibrary\Models\PlexLibrary;
use App\Domains\PlexLibrary\Models\PlexServer;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;

it('persists a plex server and reads synced_at back as an immutable date', function (): void {
    // Arrange
    $server = PlexServer::factory()->create(['synced_at' => now()]);

    // Act
    $fresh = PlexServer::query()->findOrFail($server->id);

    // Assert
    $this->assertDatabaseHas('plex_servers', ['id' => $server->id]);
    expect($fresh->synced_at)->toBeInstanceOf(CarbonImmutable::class);
});

it('rejects a duplicate _plex_clientIdentifier', function (): void {
    // Arrange
    PlexServer::factory()->create(['_plex_clientIdentifier' => 'abc-123']);

    // Act & Assert
    expect(fn () => PlexServer::factory()->create(['_plex_clientIdentifier' => 'abc-123']))
        ->toThrow(QueryException::class);
});

it('persists a plex library linked to its server', function (): void {
    // Arrange
    $server = PlexServer::factory()->create();

    // Act
    $library = PlexLibrary::factory()->create(['plex_server_id' => $server->id]);

    // Assert
    $this->assertDatabaseHas('plex_libraries', [
        'id' => $library->id,
        'plex_server_id' => $server->id,
    ]);
});

it('rejects a duplicate _plex_key under the same server', function (): void {
    // Arrange
    $server = PlexServer::factory()->create();
    PlexLibrary::factory()->create(['plex_server_id' => $server->id, '_plex_key' => '1']);

    // Act & Assert
    expect(fn () => PlexLibrary::factory()->create(['plex_server_id' => $server->id, '_plex_key' => '1']))
        ->toThrow(QueryException::class);
});

it('allows the same _plex_key under a different server', function (): void {
    // Arrange
    $serverA = PlexServer::factory()->create();
    $serverB = PlexServer::factory()->create();
    PlexLibrary::factory()->create(['plex_server_id' => $serverA->id, '_plex_key' => '1']);

    // Act
    $library = PlexLibrary::factory()->create(['plex_server_id' => $serverB->id, '_plex_key' => '1']);

    // Assert
    $this->assertDatabaseHas('plex_libraries', [
        'id' => $library->id,
        'plex_server_id' => $serverB->id,
        '_plex_key' => '1',
    ]);
});

it('returns its libraries via the libraries hasMany relation', function (): void {
    // Arrange
    $server = PlexServer::factory()->create();
    PlexLibrary::factory()->count(2)->create(['plex_server_id' => $server->id]);

    // Act
    $libraries = $server->refresh()->libraries;

    // Assert
    expect($libraries)->toBeInstanceOf(Collection::class)
        ->and($libraries)->toHaveCount(2);
});

it('deletes a servers libraries when the server is deleted', function (): void {
    // Arrange
    $server = PlexServer::factory()->create();
    $library = PlexLibrary::factory()->create(['plex_server_id' => $server->id]);

    // Act
    $server->delete();

    // Assert
    $this->assertDatabaseMissing('plex_libraries', ['id' => $library->id]);
});
