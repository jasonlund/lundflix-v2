<?php

declare(strict_types=1);

use App\Domains\Catalog\Models\Show;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;

it('persists _tmdb_* values and tmdb_synced_at', function (): void {
    // Arrange
    $show = Show::factory()->withTmdb()->make([
        '_tmdb_id' => 1399,
        '_tmdb_name' => 'Game of Thrones',
    ]);

    // Act
    $show->save();

    // Assert
    $this->assertDatabaseHas('shows', [
        'id' => $show->id,
        '_tmdb_id' => 1399,
        '_tmdb_name' => 'Game of Thrones',
    ]);
});

it('casts scalar _tmdb_* attributes when fetched fresh from the database', function (): void {
    // Arrange
    $show = Show::factory()->withTmdb()->create([
        '_tmdb_id' => 1399,
        '_tmdb_popularity' => 369.594,
        '_tmdb_vote_average' => 8.4,
        '_tmdb_first_air_date' => '2011-04-17',
        'tmdb_synced_at' => now(),
    ]);

    // Act
    $fresh = Show::query()->findOrFail($show->id);

    // Assert
    expect($fresh->_tmdb_id)->toBeInt()
        ->and($fresh->_tmdb_popularity)->toBeFloat()
        ->and($fresh->_tmdb_vote_average)->toBeFloat()
        ->and($fresh->_tmdb_first_air_date)->toBeInstanceOf(Carbon::class)
        ->and($fresh->tmdb_synced_at)->toBeInstanceOf(Carbon::class);
});

it('casts json _tmdb_* columns to arrays when fetched fresh', function (): void {
    // Arrange
    $show = Show::factory()->withTmdb()->create([
        '_tmdb_genres' => [['id' => 18, 'name' => 'Drama']],
        '_tmdb_external_ids' => ['imdb_id' => 'tt0944947', 'tvdb_id' => 121361],
    ]);

    // Act
    $fresh = Show::query()->findOrFail($show->id);

    // Assert
    expect($fresh->_tmdb_genres)->toBeArray()
        ->and($fresh->_tmdb_genres[0]['name'])->toBe('Drama')
        ->and($fresh->_tmdb_external_ids)->toBeArray()
        ->and($fresh->_tmdb_external_ids['tvdb_id'])->toBe(121361);
});

it('creates a show with a null imdb_id', function (): void {
    // Arrange & Act
    $show = Show::factory()->create([
        'imdb_id' => null,
        '_tmdb_id' => 12345,
    ]);

    // Assert
    $this->assertDatabaseHas('shows', [
        'id' => $show->id,
        'imdb_id' => null,
    ]);
});

it('rejects a duplicate non-null _tmdb_id', function (): void {
    // Arrange
    Show::factory()->create(['_tmdb_id' => 999]);

    // Act & Assert
    expect(fn () => Show::factory()->create(['_tmdb_id' => 999]))
        ->toThrow(QueryException::class);
});
