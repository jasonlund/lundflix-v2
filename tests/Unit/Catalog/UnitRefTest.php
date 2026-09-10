<?php

declare(strict_types=1);

use App\Domains\Catalog\Data\UnitRef;
use App\Domains\Catalog\Enums\UnitKind;

/**
 * No database, no factories: a UnitRef carries a kind and an id, never a model
 * instance, so the suite booting without a schema is itself part of the proof.
 */
describe('UnitRef naming one acquirable unit', function (): void {
    it('reports the id of the movie it names', function (): void {
        // Arrange
        $ref = new UnitRef(UnitKind::Movie, 41);

        // Act
        $actual = $ref->id;

        // Assert
        expect($actual)->toBe(41);
    });

    it('reports the movie kind for a movie reference', function (): void {
        // Arrange
        $ref = new UnitRef(UnitKind::Movie, 41);

        // Act
        $actual = $ref->kind;

        // Assert
        expect($actual)->toBe(UnitKind::Movie);
    });

    it('reports the id and the episode kind for an episode reference', function (): void {
        // Arrange
        // the kind and the id are the only inputs, and both are the act's own arguments

        // Act
        $ref = new UnitRef(UnitKind::Episode, 77);

        // Assert
        expect($ref->id)->toBe(77);
        expect($ref->kind)->toBe(UnitKind::Episode);
    });

    it('distinguishes a movie from an episode that share one id', function (): void {
        // Arrange
        $movieRef = new UnitRef(UnitKind::Movie, 500);

        // Act
        $episodeRef = new UnitRef(UnitKind::Episode, 500);

        // Assert
        expect($movieRef)->not->toEqual($episodeRef);
    });
});
