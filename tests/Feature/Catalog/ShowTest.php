<?php

declare(strict_types=1);

use App\Domains\Catalog\Enums\Genre;
use App\Domains\Catalog\Models\Show;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

it('persists a show row to the database', function (): void {
    // Arrange
    $show = Show::factory()->make();

    // Act
    $show->save();

    // Assert
    $this->assertDatabaseHas('shows', [
        'id' => $show->id,
        'imdb_id' => $show->imdb_id,
        'title' => $show->title,
    ]);
});

it('rejects a duplicate imdb_id', function (): void {
    // Arrange
    Show::factory()->create(['imdb_id' => 'tt1234567']);

    // Act & Assert
    expect(fn () => Show::factory()->create(['imdb_id' => 'tt1234567']))
        ->toThrow(QueryException::class);
});

it('casts typed attributes when fetched fresh from the database', function (): void {
    // Arrange
    $show = Show::factory()->create([
        'start_year' => 1999,
        'end_year' => 2007,
        'runtime' => 50,
        'num_votes' => 1_800_000,
        'average_rating' => 9.5,
        'genres' => [Genre::Action, Genre::Drama],
    ]);

    // Act
    $fresh = Show::query()->findOrFail($show->id);

    // Assert
    expect($fresh->start_year)->toBeInt()
        ->and($fresh->end_year)->toBeInt()
        ->and($fresh->runtime)->toBeInt()
        ->and($fresh->num_votes)->toBeInt()
        ->and($fresh->average_rating)->toBeFloat()
        ->and($fresh->genres)->toBeInstanceOf(Collection::class)
        ->and($fresh->genres[0])->toBeInstanceOf(Genre::class);
});

it('has start_year and end_year columns but no year column', function (): void {
    // Arrange & Act
    $hasStartYear = Schema::hasColumn('shows', 'start_year');
    $hasEndYear = Schema::hasColumn('shows', 'end_year');
    $hasYear = Schema::hasColumn('shows', 'year');

    // Assert
    expect($hasStartYear)->toBeTrue()
        ->and($hasEndYear)->toBeTrue()
        ->and($hasYear)->toBeFalse();
});

it('exposes only the searchable keys with matching values', function (): void {
    // Arrange
    $show = Show::factory()->create();

    // Act
    $searchable = $show->toSearchableArray();

    // Assert
    expect(array_keys($searchable))->toEqualCanonicalizing(['id', 'imdb_id', 'title', 'start_year', 'num_votes'])
        ->and($searchable['id'])->toBe($show->id)
        ->and($searchable['imdb_id'])->toBe($show->imdb_id)
        ->and($searchable['title'])->toBe($show->title)
        ->and($searchable['start_year'])->toBe($show->start_year)
        ->and($searchable['num_votes'])->toBe($show->num_votes);
});
