<?php

declare(strict_types=1);

use App\Domains\Catalog\Models\Movie;
use Illuminate\Support\Facades\Schema;

it('has an _imdb_id column but no imdb_id column', function (): void {
    // Arrange & Act
    $hasPrefixed = Schema::hasColumn('movies', '_imdb_id');
    $hasUnprefixed = Schema::hasColumn('movies', 'imdb_id');

    // Assert
    expect($hasPrefixed)->toBeTrue()
        ->and($hasUnprefixed)->toBeFalse();
});

it('persists a movie row to the database', function (): void {
    // Arrange
    $movie = Movie::factory()->make();

    // Act
    $movie->save();

    // Assert
    $this->assertDatabaseHas('movies', [
        'id' => $movie->id,
        '_imdb_id' => $movie->_imdb_id,
    ]);
});

it('allows two movies to share the same _imdb_id', function (): void {
    // Arrange
    Movie::factory()->create(['_imdb_id' => 'tt0000001']);

    // Act
    Movie::factory()->create(['_imdb_id' => 'tt0000001']);

    // Assert
    expect(Movie::query()->where('_imdb_id', 'tt0000001')->count())->toBe(2);
});

it('casts typed attributes when fetched fresh from the database', function (): void {
    // Arrange
    $movie = Movie::factory()->create([
        '_imdb_num_votes' => 1_800_000,
        '_imdb_average_rating' => 8.7,
    ]);

    // Act
    $fresh = Movie::query()->findOrFail($movie->id);

    // Assert
    expect($fresh->_imdb_num_votes)->toBeInt()
        ->and($fresh->_imdb_average_rating)->toBeFloat();
});
