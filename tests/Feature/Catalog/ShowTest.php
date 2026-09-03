<?php

declare(strict_types=1);

use App\Domains\Catalog\Models\Show;
use Illuminate\Support\Facades\Schema;

describe('shows _imdb_id column', function (): void {
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
});

describe('shows IMDb rating columns', function (): void {
    it('round-trips the IMDb rating attributes under their verbatim camelCase names', function (): void {
        // Arrange
        $show = Show::factory()->create([
            '_imdb_numVotes' => 1_800_000,
            '_imdb_averageRating' => 9.5,
        ]);

        // Act
        $fresh = Show::query()->findOrFail($show->id);

        // Assert
        expect($fresh->_imdb_numVotes)->toBeInt()
            ->and($fresh->_imdb_numVotes)->toBe(1_800_000)
            ->and($fresh->_imdb_averageRating)->toBeFloat()
            ->and($fresh->_imdb_averageRating)->toBe(9.5);
    });

    it('has the camelCase IMDb rating columns and no snake_case ones on shows', function (): void {
        // Arrange & Act
        $hasCamel = Schema::hasColumn('shows', '_imdb_numVotes')
            && Schema::hasColumn('shows', '_imdb_averageRating');
        $hasSnake = Schema::hasColumn('shows', '_imdb_num_votes')
            || Schema::hasColumn('shows', '_imdb_average_rating');

        // Assert
        expect($hasCamel)->toBeTrue()
            ->and($hasSnake)->toBeFalse();
    });
});

describe('shows IMDb basics and akas columns', function (): void {
    it('has all eight IMDb basics and akas columns on shows', function (): void {
        // Arrange & Act
        $missing = array_values(array_filter([
            '_imdb_titleType',
            '_imdb_primaryTitle',
            '_imdb_originalTitle',
            '_imdb_startYear',
            '_imdb_endYear',
            '_imdb_runtimeMinutes',
            '_imdb_genres',
            '_imdb_akas',
        ], fn (string $column): bool => ! Schema::hasColumn('shows', $column)));

        // Assert
        expect($missing)->toBe([]);
    });

    // Was asserted absent while adult rows were dropped pre-upsert; ADR-0004
    // reversed that, so the flag is now stored and filtered at read.
    it('has the _imdb_isAdult refusal column on shows', function (): void {
        // Arrange & Act
        $hasIsAdult = Schema::hasColumn('shows', '_imdb_isAdult');

        // Assert
        expect($hasIsAdult)->toBeTrue();
    });

    it('round-trips the IMDb basics numerics as ints and the genres and akas as arrays', function (): void {
        // Arrange
        $show = Show::factory()->create([
            '_imdb_titleType' => 'tvSeries',
            '_imdb_primaryTitle' => 'The Wire',
            '_imdb_originalTitle' => 'The Wire',
            '_imdb_startYear' => 2002,
            '_imdb_endYear' => 2008,
            '_imdb_runtimeMinutes' => 59,
            '_imdb_genres' => ['Crime', 'Drama', 'Thriller'],
            '_imdb_akas' => ['The Wire', 'Bajo escucha'],
        ]);

        // Act
        $fresh = Show::query()->findOrFail($show->id);

        // Assert
        expect($fresh->_imdb_titleType)->toBe('tvSeries')
            ->and($fresh->_imdb_primaryTitle)->toBe('The Wire')
            ->and($fresh->_imdb_originalTitle)->toBe('The Wire')
            ->and($fresh->_imdb_startYear)->toBe(2002)
            ->and($fresh->_imdb_endYear)->toBe(2008)
            ->and($fresh->_imdb_runtimeMinutes)->toBe(59)
            ->and($fresh->_imdb_genres)->toBe(['Crime', 'Drama', 'Thriller'])
            ->and($fresh->_imdb_akas)->toBe(['The Wire', 'Bajo escucha']);
    });
});
