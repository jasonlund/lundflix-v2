<?php

declare(strict_types=1);

use App\Domains\Catalog\Models\Show;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;

it('persists _tvdb_* values and tvdb_synced_at', function (): void {
    // Arrange
    $show = Show::factory()->withTvdb()->make([
        '_tvdb_id' => 121361,
        '_tvdb_name' => 'Game of Thrones',
    ]);

    // Act
    $show->save();

    // Assert
    $this->assertDatabaseHas('shows', [
        'id' => $show->id,
        '_tvdb_id' => 121361,
        '_tvdb_name' => 'Game of Thrones',
    ]);
});

it('casts scalar _tvdb_* attributes when fetched fresh from the database', function (): void {
    // Arrange
    $show = Show::factory()->withTvdb()->create([
        '_tvdb_id' => 121361,
        '_tvdb_year' => '1997',
        '_tvdb_averageRuntime' => 60,
        '_tvdb_score' => 520006.0,
        '_tvdb_firstAired' => '1997-09-30',
        'tvdb_synced_at' => now(),
    ]);

    // Act
    $fresh = Show::query()->findOrFail($show->id);

    // Assert
    expect($fresh->_tvdb_id)->toBeInt()
        ->and($fresh->_tvdb_year)->toBeInt()
        ->and($fresh->_tvdb_averageRuntime)->toBeInt()
        ->and($fresh->_tvdb_score)->toBeFloat()
        ->and($fresh->_tvdb_firstAired)->toBeInstanceOf(Carbon::class)
        ->and($fresh->tvdb_synced_at)->toBeInstanceOf(Carbon::class);
});

it('casts json _tvdb_* columns to arrays when fetched fresh', function (): void {
    // Arrange
    $show = Show::factory()->withTvdb()->create([
        '_tvdb_remoteIds' => [['id' => 'tt0903747', 'type' => 2, 'sourceName' => 'IMDB']],
        '_tvdb_genres' => [['id' => 12, 'name' => 'Drama', 'slug' => 'drama']],
        '_tvdb_status' => ['id' => 2, 'name' => 'Ended', 'recordType' => 'series', 'keepUpdated' => false],
    ]);

    // Act
    $fresh = Show::query()->findOrFail($show->id);

    // Assert
    expect($fresh->_tvdb_remoteIds)->toBeArray()
        ->and($fresh->_tvdb_remoteIds[0]['sourceName'])->toBe('IMDB')
        ->and($fresh->_tvdb_genres)->toBeArray()
        ->and($fresh->_tvdb_genres[0]['name'])->toBe('Drama')
        ->and($fresh->_tvdb_status)->toBeArray()
        ->and($fresh->_tvdb_status['name'])->toBe('Ended');
});

it('rejects a duplicate non-null _tvdb_id', function (): void {
    // Arrange
    Show::factory()->create(['_tvdb_id' => 999]);

    // Act & Assert
    expect(fn () => Show::factory()->create(['_tvdb_id' => 999]))
        ->toThrow(QueryException::class);
});
