<?php

declare(strict_types=1);

use App\Domains\Catalog\Data\UnitRef;
use App\Domains\Catalog\Enums\UnitKind;
use App\Domains\Catalog\Models\Episode;
use App\Domains\Catalog\Models\Movie;
use App\Domains\PlexLibrary\Contracts\ReportsArrivals;
use App\Domains\PlexLibrary\Data\UnitArrival;
use App\Domains\PlexLibrary\Models\PlexEpisode;
use App\Domains\PlexLibrary\Models\PlexMovie;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * The arrival time reported for one unit, looked up by kind and id together
 * since ids repeat across kinds; null when the unit is missing from the result.
 *
 * @param  Collection<int, UnitArrival>  $arrivals
 */
function arrivalTimeFor(Collection $arrivals, UnitKind $kind, int $id): ?CarbonImmutable
{
    return $arrivals
        ->first(fn (UnitArrival $arrival): bool => $arrival->unit->kind === $kind && $arrival->unit->id === $id)
        ?->arrivedAt;
}

describe('arrivals() arrival time', function (): void {
    it('reports a mirrored movie as arriving when its mirror row was added', function (): void {
        // Arrange
        $movie = Movie::factory()->create(['_tmdb_id' => 550]);
        PlexMovie::factory()->create([
            '_tmdb_id' => 550,
            '_plex_addedAt' => CarbonImmutable::parse('2026-03-01 12:00:00'),
        ]);

        // Act
        $arrivals = resolve(ReportsArrivals::class)->arrivals([new UnitRef(UnitKind::Movie, $movie->id)]);

        // Assert
        expect($arrivals)->toHaveCount(1);
        expect(arrivalTimeFor($arrivals, UnitKind::Movie, $movie->id))
            ->toEqual(CarbonImmutable::parse('2026-03-01 12:00:00'));
    });

    it('reports a mirrored episode as arriving when its crosswalk-matched mirror row was added', function (): void {
        // Arrange
        $episode = Episode::factory()->create([
            '_tvdb_id' => 3254641,
            '_tvdb_seasonNumber' => 1,
            '_tvdb_number' => 1,
        ]);
        PlexEpisode::factory()->create([
            '_tvdb_id' => 3254641,
            '_plex_addedAt' => CarbonImmutable::parse('2026-04-15 08:30:00'),
        ]);

        // Act
        $arrivals = resolve(ReportsArrivals::class)->arrivals([new UnitRef(UnitKind::Episode, $episode->id)]);

        // Assert
        expect($arrivals)->toHaveCount(1);
        expect(arrivalTimeFor($arrivals, UnitKind::Episode, $episode->id))
            ->toEqual(CarbonImmutable::parse('2026-04-15 08:30:00'));
    });

    it('reports the earliest arrival for a unit mirrored by more than one row', function (): void {
        // The later row is inserted first, so a read that keeps whichever row it
        // meets first cannot pass for one that keeps the earliest.
        // Arrange
        $movie = Movie::factory()->create(['_tmdb_id' => 550]);
        PlexMovie::factory()->create([
            '_tmdb_id' => 550,
            '_plex_addedAt' => CarbonImmutable::parse('2026-05-01 00:00:00'),
        ]);
        PlexMovie::factory()->create([
            '_tmdb_id' => 550,
            '_plex_addedAt' => CarbonImmutable::parse('2026-02-01 00:00:00'),
        ]);

        // Act
        $arrivals = resolve(ReportsArrivals::class)->arrivals([new UnitRef(UnitKind::Movie, $movie->id)]);

        // Assert
        expect($arrivals)->toHaveCount(1);
        expect(arrivalTimeFor($arrivals, UnitKind::Movie, $movie->id))
            ->toEqual(CarbonImmutable::parse('2026-02-01 00:00:00'));
    });
});

describe('arrivals() units left out', function (): void {
    it('leaves out a unit with no mirror row', function (): void {
        // Arrange
        $movie = Movie::factory()->create(['_imdb_id' => 'tt0137523', '_tmdb_id' => 550]);

        // Act
        $arrivals = resolve(ReportsArrivals::class)->arrivals([new UnitRef(UnitKind::Movie, $movie->id)]);

        // Assert
        expect($arrivals)->toBeEmpty();
    });

    it('leaves out a mirrored unit whose mirror row carries no arrival time', function (): void {
        // Arrange
        $movie = Movie::factory()->create(['_tmdb_id' => 550]);
        PlexMovie::factory()->create([
            '_tmdb_id' => 550,
            '_plex_addedAt' => null,
        ]);

        // Act
        $arrivals = resolve(ReportsArrivals::class)->arrivals([new UnitRef(UnitKind::Movie, $movie->id)]);

        // Assert
        expect($arrivals)->toBeEmpty();
    });
});
