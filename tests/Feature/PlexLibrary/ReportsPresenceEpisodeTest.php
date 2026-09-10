<?php

declare(strict_types=1);

use App\Domains\Catalog\Data\UnitRef;
use App\Domains\Catalog\Enums\UnitKind;
use App\Domains\Catalog\Models\Episode;
use App\Domains\Catalog\Models\Show;
use App\Domains\PlexLibrary\Contracts\ReportsPresence;
use App\Domains\PlexLibrary\Models\PlexEpisode;
use App\Domains\PlexLibrary\Models\PlexSeason;
use App\Domains\PlexLibrary\Models\PlexShow;

describe('has() episode presence', function (): void {
    it('reports an episode mirrored under a matching crosswalk id as present', function (): void {
        // The mirror row's season/episode numbers deliberately disagree with the
        // catalog's, so only the crosswalk id can carry this match.
        // Arrange
        $episode = Episode::factory()->create([
            '_tvdb_id' => 3254641,
            '_tvdb_seasonNumber' => 1,
            '_tvdb_number' => 1,
        ]);
        PlexEpisode::factory()->create([
            '_tvdb_id' => 3254641,
            '_plex_parentIndex' => 7,
            '_plex_index' => 13,
        ]);

        // Act
        $present = resolve(ReportsPresence::class)->has(new UnitRef(UnitKind::Episode, $episode->id));

        // Assert
        expect($present)->toBeTrue();
    });

    it('reports an episode as absent when its show is mirrored but the episode is not', function (): void {
        // Arrange
        $show = Show::factory()->withTvdb()->create(['_tvdb_id' => 121361]);
        $episode = Episode::factory()->for($show)->create([
            '_tvdb_id' => 3254641,
            '_tvdb_seasonNumber' => 1,
            '_tvdb_number' => 1,
        ]);
        $plexShow = PlexShow::factory()->create(['_tvdb_id' => 121361]);
        $plexSeason = PlexSeason::factory()->create(['plex_show_id' => $plexShow->id]);
        PlexEpisode::factory()->create([
            'plex_season_id' => $plexSeason->id,
            '_tvdb_id' => 3254642,
            '_plex_parentIndex' => 1,
            '_plex_index' => 2,
        ]);

        // Act
        $present = resolve(ReportsPresence::class)->has(new UnitRef(UnitKind::Episode, $episode->id));

        // Assert
        expect($present)->toBeFalse();
    });

    it('reports an episode whose mirror row carries no crosswalk id as present by show, season and number', function (): void {
        // Arrange
        $show = Show::factory()->withTvdb()->create(['_tvdb_id' => 121361]);
        $episode = Episode::factory()->for($show)->create([
            '_tvdb_id' => 3254641,
            '_tvdb_seasonNumber' => 4,
            '_tvdb_number' => 9,
        ]);
        $plexShow = PlexShow::factory()->create(['_tvdb_id' => 121361]);
        $plexSeason = PlexSeason::factory()->create(['plex_show_id' => $plexShow->id]);
        PlexEpisode::factory()->create([
            'plex_season_id' => $plexSeason->id,
            '_tvdb_id' => null,
            '_plex_parentIndex' => 4,
            '_plex_index' => 9,
        ]);

        // Act
        $present = resolve(ReportsPresence::class)->has(new UnitRef(UnitKind::Episode, $episode->id));

        // Assert
        expect($present)->toBeTrue();
    });
});
