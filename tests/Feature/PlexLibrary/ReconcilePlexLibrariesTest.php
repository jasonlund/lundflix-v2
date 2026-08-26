<?php

declare(strict_types=1);

use App\Domains\PlexLibrary\Actions\ReconcilePlexLibraries;
use App\Domains\PlexLibrary\Models\PlexLibrary;
use App\Domains\PlexLibrary\Models\PlexMovie;
use App\Domains\PlexLibrary\Models\PlexServer;

/**
 * Seeded from a real member of .context/plex-captures/sections.json
 * (the /library/sections Directory list decoded to an array). Passed
 * straight to handle() — not a faked HTTP body — so an inline artist
 * member is fine; the byte-exact-fixture rule does not apply.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function plexSection(array $overrides = []): array
{
    return [...[
        'key' => '1',
        'type' => 'movie',
        'title' => 'Movies',
        'uuid' => '5f4d87a0-f71f-4428-8e0a-ddc49c759b47',
        'updatedAt' => 1777444600,
    ], ...$overrides];
}

describe('handle() section upsert', function (): void {
    it('inserts a plex_libraries row per movie or show section', function (): void {
        // Arrange
        $server = PlexServer::factory()->create();

        // Act
        (new ReconcilePlexLibraries)->handle($server, [
            plexSection(['key' => '1', 'type' => 'movie', 'title' => 'Movies', 'uuid' => '5f4d87a0-f71f-4428-8e0a-ddc49c759b47']),
            plexSection(['key' => '2', 'type' => 'show', 'title' => 'TV Shows', 'uuid' => '0be8114b-46ad-4ca1-872d-bb587e638350']),
        ]);

        // Assert
        $this->assertDatabaseCount('plex_libraries', 2);
        $this->assertDatabaseHas('plex_libraries', [
            'plex_server_id' => $server->id,
            '_plex_key' => '1',
            '_plex_type' => 'movie',
            '_plex_title' => 'Movies',
            '_plex_uuid' => '5f4d87a0-f71f-4428-8e0a-ddc49c759b47',
        ]);
        $this->assertDatabaseHas('plex_libraries', [
            'plex_server_id' => $server->id,
            '_plex_key' => '2',
            '_plex_type' => 'show',
            '_plex_title' => 'TV Shows',
            '_plex_uuid' => '0be8114b-46ad-4ca1-872d-bb587e638350',
        ]);
    });

    it('ignores a non movie or show section', function (): void {
        // Arrange
        $server = PlexServer::factory()->create();

        // Act
        (new ReconcilePlexLibraries)->handle($server, [
            plexSection(['type' => 'movie', 'key' => '1']),
            plexSection(['type' => 'artist', 'key' => '3']),
        ]);

        // Assert
        $this->assertDatabaseCount('plex_libraries', 1);
        $this->assertDatabaseMissing('plex_libraries', ['_plex_key' => '3']);
    });

    it('updates in place on plex_server_id and _plex_key when re-run', function (): void {
        // Arrange
        $server = PlexServer::factory()->create();
        (new ReconcilePlexLibraries)->handle($server, [
            plexSection(['key' => '1', 'type' => 'movie', 'title' => 'Movies']),
        ]);

        // Act
        (new ReconcilePlexLibraries)->handle($server, [
            plexSection(['key' => '1', 'type' => 'movie', 'title' => 'Renamed Movies']),
        ]);

        // Assert
        $this->assertDatabaseCount('plex_libraries', 1);
        $this->assertDatabaseHas('plex_libraries', [
            'plex_server_id' => $server->id,
            '_plex_key' => '1',
            '_plex_title' => 'Renamed Movies',
        ]);
    });

    it('stamps synced_at on each upserted library row', function (): void {
        // Arrange
        $server = PlexServer::factory()->create();

        // Act
        (new ReconcilePlexLibraries)->handle($server, [
            plexSection(['key' => '1', 'type' => 'movie']),
            plexSection(['key' => '2', 'type' => 'show']),
        ]);

        // Assert
        expect(PlexLibrary::whereNull('synced_at')->count())->toBe(0)
            ->and(PlexLibrary::count())->toBeGreaterThan(0);
    });

});

describe('handle() prune', function (): void {
    it('hard-deletes a library absent from the incoming sections', function (): void {
        // Arrange
        $server = PlexServer::factory()->create();
        $library = PlexLibrary::factory()->create(['plex_server_id' => $server->id, '_plex_key' => '9']);

        // Act
        (new ReconcilePlexLibraries)->handle($server, []);

        // Assert
        $this->assertDatabaseMissing('plex_libraries', ['id' => $library->id]);
    });

    it('cascade-deletes child items when a library is pruned', function (): void {
        // Arrange
        $server = PlexServer::factory()->create();
        $library = PlexLibrary::factory()->create(['plex_server_id' => $server->id, '_plex_key' => '9']);
        $movie = PlexMovie::factory()->create(['plex_library_id' => $library->id, 'plex_server_id' => $server->id]);

        // Act
        (new ReconcilePlexLibraries)->handle($server, []);

        // Assert
        $this->assertDatabaseMissing('plex_movies', ['id' => $movie->id]);
    });

    it('keeps present sections while pruning absent ones', function (): void {
        // Arrange
        $server = PlexServer::factory()->create();
        $kept = PlexLibrary::factory()->create(['plex_server_id' => $server->id, '_plex_key' => '1']);
        $keptMovie = PlexMovie::factory()->create(['plex_library_id' => $kept->id, 'plex_server_id' => $server->id]);
        $pruned = PlexLibrary::factory()->create(['plex_server_id' => $server->id, '_plex_key' => '2']);
        $prunedMovie = PlexMovie::factory()->create(['plex_library_id' => $pruned->id, 'plex_server_id' => $server->id]);

        // Act
        (new ReconcilePlexLibraries)->handle($server, [
            plexSection(['key' => '1', 'type' => 'movie']),
        ]);

        // Assert
        $this->assertDatabaseHas('plex_libraries', ['id' => $kept->id]);
        $this->assertDatabaseHas('plex_movies', ['id' => $keptMovie->id]);
        $this->assertDatabaseMissing('plex_libraries', ['id' => $pruned->id]);
        $this->assertDatabaseMissing('plex_movies', ['id' => $prunedMovie->id]);
    });

    it('scopes the prune to the given server', function (): void {
        // Arrange
        $server = PlexServer::factory()->create();
        $other = PlexServer::factory()->create();
        PlexLibrary::factory()->create(['plex_server_id' => $server->id, '_plex_key' => '9']);
        PlexLibrary::factory()->create(['plex_server_id' => $other->id, '_plex_key' => '9']);

        // Act
        (new ReconcilePlexLibraries)->handle($server, []);

        // Assert
        $this->assertDatabaseMissing('plex_libraries', ['plex_server_id' => $server->id, '_plex_key' => '9']);
        $this->assertDatabaseHas('plex_libraries', ['plex_server_id' => $other->id, '_plex_key' => '9']);
    });
});
