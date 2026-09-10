<?php

declare(strict_types=1);

use App\Domains\Catalog\Data\UnitRef;
use App\Domains\Catalog\Enums\UnitKind;
use App\Domains\Catalog\Models\Movie;
use App\Domains\PlexLibrary\Contracts\ReportsPresence;
use App\Domains\PlexLibrary\Models\PlexMovie;

describe('has() movie presence', function (): void {
    it('reports a movie mirrored on the server as present', function (): void {
        // Arrange
        $movie = Movie::factory()->create(['_tmdb_id' => 550]);
        PlexMovie::factory()->create(['_tmdb_id' => 550]);

        // Act
        $present = resolve(ReportsPresence::class)->has(new UnitRef(UnitKind::Movie, $movie->id));

        // Assert
        expect($present)->toBeTrue();
    });

    it('reports a movie with no mirror row as absent', function (): void {
        // Arrange
        $movie = Movie::factory()->create(['_imdb_id' => 'tt0137523', '_tmdb_id' => 550]);

        // Act
        $present = resolve(ReportsPresence::class)->has(new UnitRef(UnitKind::Movie, $movie->id));

        // Assert
        expect($present)->toBeFalse();
    });

    it('reports a movie as absent when the only mirror row belongs to a different title', function (): void {
        // Arrange
        $movie = Movie::factory()->create(['_imdb_id' => 'tt0137523', '_tmdb_id' => 550]);
        $other = Movie::factory()->create(['_imdb_id' => 'tt0110912', '_tmdb_id' => 680]);
        PlexMovie::factory()->create([
            '_imdb_id' => $other->_imdb_id,
            '_tmdb_id' => $other->_tmdb_id,
        ]);

        // Act
        $present = resolve(ReportsPresence::class)->has(new UnitRef(UnitKind::Movie, $movie->id));

        // Assert
        expect($present)->toBeFalse();
    });
});
