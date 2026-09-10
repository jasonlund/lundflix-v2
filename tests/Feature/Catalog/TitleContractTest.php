<?php

declare(strict_types=1);

use App\Domains\Catalog\Models\Movie;
use App\Domains\Catalog\Models\Show;

describe('Movie as a published Title', function (): void {
    it('reports its identity and display title', function (): void {
        // Arrange
        $movie = Movie::factory()->withTmdb()->create(['_tmdb_title' => 'Blade Runner']);

        // Act
        $actual = [$movie->catalogId(), $movie->displayTitle()];

        // Assert
        expect($actual)->toBe([$movie->id, 'Blade Runner']);
    });

    it('reports its crosswalk ids', function (): void {
        // Arrange
        $movie = Movie::factory()->withTmdb()->create([
            '_imdb_id' => 'tt0083658',
            '_tmdb_id' => 78,
        ]);

        // Act
        $actual = [$movie->imdbId(), $movie->tmdbId()];

        // Assert
        expect($actual)->toBe(['tt0083658', 78]);
    });
});

describe('Show as a published Title', function (): void {
    // Both names are set so the assertion pins TVDB PRECEDENCE rather than mere
    // presence — with _tmdb_name absent, a reversed _tmdb_name ?? _tvdb_name
    // union would pass too.
    it('reports its display title from its TVDB name', function (): void {
        // Arrange
        $show = Show::factory()->withTmdb()->withTvdb()->create([
            '_tvdb_name' => 'The Wire',
            '_tmdb_name' => 'The Wire (TMDB)',
        ]);

        // Act
        $actual = $show->displayTitle();

        // Assert
        expect($actual)->toBe('The Wire');
    });

    it('falls back to its TMDB name when it has no TVDB name', function (): void {
        // Arrange
        $show = Show::factory()->withTmdb()->create(['_tmdb_name' => 'Deadwood']);

        // Act
        $actual = $show->displayTitle();

        // Assert
        expect($actual)->toBe('Deadwood');
    });

    it('reports its identity and crosswalk ids', function (): void {
        // Arrange
        $show = Show::factory()->withTmdb()->withTvdb()->create([
            '_imdb_id' => 'tt0306414',
            '_tmdb_id' => 1438,
        ]);

        // Act
        $actual = [$show->catalogId(), $show->imdbId(), $show->tmdbId()];

        // Assert
        expect($actual)->toBe([$show->id, 'tt0306414', 1438]);
    });
});
