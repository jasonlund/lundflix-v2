<?php

declare(strict_types=1);

use App\Domains\Catalog\Models\Movie;

describe('shouldBeSearchable() gone filter', function (): void {
    // A movie stamped `tmdb_gone_at` is one TMDB has stopped serving, so the
    // catalog can no longer stand behind the copy it holds — it stays as a row
    // but must not be findable.
    it('drops a gone movie out of the searchable set', function (): void {
        // Arrange
        $movie = Movie::factory()->withTmdb()->create(['tmdb_gone_at' => now()]);

        // Act
        $searchable = $movie->shouldBeSearchable();

        // Assert
        expect($searchable)->toBeFalse();
    });

    it('keeps a movie that is neither gone nor refused searchable', function (): void {
        // Arrange
        $movie = Movie::factory()->withTmdb()->create([
            'tmdb_gone_at' => null,
            '_imdb_isAdult' => false,
            '_tmdb_adult' => false,
            '_tmdb_softcore' => false,
            '_tmdb_video' => false,
        ]);

        // Act
        $searchable = $movie->shouldBeSearchable();

        // Assert
        expect($searchable)->toBeTrue();
    });

    // The two grounds compose rather than replace one another: a gone check that
    // short-circuited the refusal one would let a refused title back in the moment
    // TMDB started serving it again.
    it('leaves a movie that is both gone and refused unsearchable', function (): void {
        // Arrange
        $movie = Movie::factory()->withTmdb()->create([
            'tmdb_gone_at' => now(),
            '_tmdb_adult' => true,
        ]);

        // Act
        $searchable = $movie->shouldBeSearchable();

        // Assert
        expect($searchable)->toBeFalse();
    });
});
