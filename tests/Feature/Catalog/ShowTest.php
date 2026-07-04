<?php

declare(strict_types=1);

use App\Domains\Catalog\Models\Show;
use Illuminate\Support\Facades\Schema;

it('has an _imdb_id column but no imdb_id column', function (): void {
    // Arrange & Act
    $hasPrefixed = Schema::hasColumn('shows', '_imdb_id');
    $hasUnprefixed = Schema::hasColumn('shows', 'imdb_id');

    // Assert
    expect($hasPrefixed)->toBeTrue()
        ->and($hasUnprefixed)->toBeFalse();
});

it('persists a show row to the database', function (): void {
    // Arrange
    $show = Show::factory()->make();

    // Act
    $show->save();

    // Assert
    $this->assertDatabaseHas('shows', [
        'id' => $show->id,
        '_imdb_id' => $show->_imdb_id,
    ]);
});

it('allows two shows to share the same _imdb_id', function (): void {
    // Arrange
    Show::factory()->create(['_imdb_id' => 'tt0000001']);

    // Act
    Show::factory()->create(['_imdb_id' => 'tt0000001']);

    // Assert
    expect(Show::query()->where('_imdb_id', 'tt0000001')->count())->toBe(2);
});

it('casts typed attributes when fetched fresh from the database', function (): void {
    // Arrange
    $show = Show::factory()->create([
        '_imdb_num_votes' => 1_800_000,
        '_imdb_average_rating' => 9.5,
    ]);

    // Act
    $fresh = Show::query()->findOrFail($show->id);

    // Assert
    expect($fresh->_imdb_num_votes)->toBeInt()
        ->and($fresh->_imdb_average_rating)->toBeFloat();
});
