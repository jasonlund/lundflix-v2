<?php

declare(strict_types=1);

use App\Domains\PlexLibrary\Models\PlexLibrary;
use App\Domains\PlexLibrary\Models\PlexMovie;
use App\Domains\PlexLibrary\Models\PlexShow;
use Illuminate\Database\QueryException;

it('persists a plex movie linked to its server and library', function (): void {
    // Arrange
    $library = PlexLibrary::factory()->create();

    // Act
    $movie = PlexMovie::factory()->create([
        'plex_server_id' => $library->plex_server_id,
        'plex_library_id' => $library->id,
    ]);

    // Assert
    $this->assertDatabaseHas('plex_movies', [
        'id' => $movie->id,
        'plex_server_id' => $library->plex_server_id,
        'plex_library_id' => $library->id,
    ]);
});

it('rejects a duplicate _plex_ratingKey under the same server on movies', function (): void {
    // Arrange
    $library = PlexLibrary::factory()->create();
    PlexMovie::factory()->create([
        'plex_server_id' => $library->plex_server_id,
        'plex_library_id' => $library->id,
        '_plex_ratingKey' => '55',
    ]);

    // Act & Assert
    expect(fn () => PlexMovie::factory()->create([
        'plex_server_id' => $library->plex_server_id,
        'plex_library_id' => $library->id,
        '_plex_ratingKey' => '55',
    ]))->toThrow(QueryException::class);
});

it('allows the same _plex_ratingKey under a different server on movies', function (): void {
    // Arrange
    $libraryA = PlexLibrary::factory()->create();
    $libraryB = PlexLibrary::factory()->create();
    PlexMovie::factory()->create([
        'plex_server_id' => $libraryA->plex_server_id,
        'plex_library_id' => $libraryA->id,
        '_plex_ratingKey' => '55',
    ]);

    // Act
    $movie = PlexMovie::factory()->create([
        'plex_server_id' => $libraryB->plex_server_id,
        'plex_library_id' => $libraryB->id,
        '_plex_ratingKey' => '55',
    ]);

    // Assert
    $this->assertDatabaseHas('plex_movies', [
        'id' => $movie->id,
        'plex_server_id' => $libraryB->plex_server_id,
        '_plex_ratingKey' => '55',
    ]);
});

it('persists a plex show including its leaf and child counts', function (): void {
    // Arrange
    $library = PlexLibrary::factory()->create();

    // Act
    $show = PlexShow::factory()->create([
        'plex_server_id' => $library->plex_server_id,
        'plex_library_id' => $library->id,
        '_plex_leafCount' => 42,
        '_plex_childCount' => 3,
    ]);

    // Assert
    $this->assertDatabaseHas('plex_shows', [
        'id' => $show->id,
        '_plex_leafCount' => 42,
        '_plex_childCount' => 3,
    ]);
});

it('rejects a duplicate _plex_ratingKey under the same server on shows', function (): void {
    // Arrange
    $library = PlexLibrary::factory()->create();
    PlexShow::factory()->create([
        'plex_server_id' => $library->plex_server_id,
        'plex_library_id' => $library->id,
        '_plex_ratingKey' => '77',
    ]);

    // Act & Assert
    expect(fn () => PlexShow::factory()->create([
        'plex_server_id' => $library->plex_server_id,
        'plex_library_id' => $library->id,
        '_plex_ratingKey' => '77',
    ]))->toThrow(QueryException::class);
});

it('deletes a librarys movies and shows when the library is deleted', function (): void {
    // Arrange
    $library = PlexLibrary::factory()->create();
    $movie = PlexMovie::factory()->create([
        'plex_server_id' => $library->plex_server_id,
        'plex_library_id' => $library->id,
    ]);
    $show = PlexShow::factory()->create([
        'plex_server_id' => $library->plex_server_id,
        'plex_library_id' => $library->id,
    ]);

    // Act
    $library->delete();

    // Assert
    $this->assertDatabaseMissing('plex_movies', ['id' => $movie->id]);
    $this->assertDatabaseMissing('plex_shows', ['id' => $show->id]);
});

it('round-trips _plex_ratingKey as a php string, never int-cast', function (): void {
    // Arrange
    $movie = PlexMovie::factory()->create(['_plex_ratingKey' => '12345']);

    // Act
    $fresh = PlexMovie::query()->findOrFail($movie->id);

    // Assert
    expect($fresh->_plex_ratingKey)->toBeString()->toBe('12345');
});
