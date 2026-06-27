<?php

declare(strict_types=1);

use App\Domains\Catalog\Enums\Genre;
use App\Domains\Catalog\Enums\TitleType;
use App\Domains\Catalog\Models\Show;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
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
        '_imdb_primary_title' => $show->_imdb_primary_title,
    ]);
});

it('rejects a duplicate _imdb_id', function (): void {
    // Arrange
    Show::factory()->create(['_imdb_id' => 'tt1234567']);

    // Act & Assert
    expect(fn () => Show::factory()->create(['_imdb_id' => 'tt1234567']))
        ->toThrow(QueryException::class);
});

it('casts typed attributes when fetched fresh from the database', function (): void {
    // Arrange
    $show = Show::factory()->create([
        '_imdb_start_year' => 1999,
        '_imdb_end_year' => 2007,
        '_imdb_runtime_minutes' => 50,
        '_imdb_num_votes' => 1_800_000,
        '_imdb_average_rating' => 9.5,
        '_imdb_genres' => [Genre::Action, Genre::Drama],
    ]);

    // Act
    $fresh = Show::query()->findOrFail($show->id);

    // Assert
    expect($fresh->_imdb_start_year)->toBeInt()
        ->and($fresh->_imdb_end_year)->toBeInt()
        ->and($fresh->_imdb_runtime_minutes)->toBeInt()
        ->and($fresh->_imdb_num_votes)->toBeInt()
        ->and($fresh->_imdb_average_rating)->toBeFloat()
        ->and($fresh->_imdb_title_type)->toBeInstanceOf(TitleType::class)
        ->and($fresh->_imdb_genres)->toBeInstanceOf(Collection::class)
        ->and($fresh->_imdb_genres[0])->toBeInstanceOf(Genre::class);
});

it('has _imdb-prefixed descriptive columns and no unprefixed legacy columns', function (): void {
    // Arrange & Act
    $prefixed = [
        '_imdb_primary_title',
        '_imdb_title_type',
        '_imdb_start_year',
        '_imdb_end_year',
        '_imdb_runtime_minutes',
        '_imdb_genres',
    ];
    $legacy = ['title', 'title_type', 'start_year', 'end_year', 'runtime', 'genres', 'year'];

    // Assert
    foreach ($prefixed as $column) {
        expect(Schema::hasColumn('shows', $column))->toBeTrue();
    }
    foreach ($legacy as $column) {
        expect(Schema::hasColumn('shows', $column))->toBeFalse();
    }
});

it('exposes only the searchable keys with matching values', function (): void {
    // Arrange
    $show = Show::factory()->create();

    // Act
    $searchable = $show->toSearchableArray();

    // Assert
    expect(array_keys($searchable))->toEqualCanonicalizing(['id', 'imdb_id', 'title', 'start_year', 'num_votes'])
        ->and($searchable['id'])->toBe($show->id)
        ->and($searchable['imdb_id'])->toBe($show->_imdb_id)
        ->and($searchable['title'])->toBe($show->_imdb_primary_title)
        ->and($searchable['start_year'])->toBe($show->_imdb_start_year)
        ->and($searchable['num_votes'])->toBe($show->_imdb_num_votes);
});
