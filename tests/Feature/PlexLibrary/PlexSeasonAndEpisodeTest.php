<?php

declare(strict_types=1);

use App\Domains\PlexLibrary\Models\PlexEpisode;
use App\Domains\PlexLibrary\Models\PlexSeason;
use App\Domains\PlexLibrary\Models\PlexShow;
use Illuminate\Database\QueryException;

describe('plex season persistence', function (): void {
    it('persists a plex season linked to its server and show', function (): void {
        // Arrange
        $show = PlexShow::factory()->create();

        // Act
        $season = PlexSeason::factory()->create([
            'plex_server_id' => $show->plex_server_id,
            'plex_show_id' => $show->id,
        ]);

        // Assert
        $this->assertDatabaseHas('plex_seasons', [
            'id' => $season->id,
            'plex_server_id' => $show->plex_server_id,
            'plex_show_id' => $show->id,
        ]);
    });

    it('rejects a duplicate _plex_ratingKey under the same server on seasons', function (): void {
        // Arrange
        $show = PlexShow::factory()->create();
        PlexSeason::factory()->create([
            'plex_server_id' => $show->plex_server_id,
            'plex_show_id' => $show->id,
            '_plex_ratingKey' => '88',
        ]);

        // Act & Assert
        expect(fn () => PlexSeason::factory()->create([
            'plex_server_id' => $show->plex_server_id,
            'plex_show_id' => $show->id,
            '_plex_ratingKey' => '88',
        ]))->toThrow(QueryException::class);
    });
});

describe('plex episode persistence', function (): void {
    it('persists a plex episode linked to its server, show, and season', function (): void {
        // Arrange
        $show = PlexShow::factory()->create();
        $season = PlexSeason::factory()->create([
            'plex_server_id' => $show->plex_server_id,
            'plex_show_id' => $show->id,
        ]);

        // Act
        $episode = PlexEpisode::factory()->create([
            'plex_server_id' => $show->plex_server_id,
            'plex_show_id' => $show->id,
            'plex_season_id' => $season->id,
        ]);

        // Assert
        $this->assertDatabaseHas('plex_episodes', [
            'id' => $episode->id,
            'plex_server_id' => $show->plex_server_id,
            'plex_show_id' => $show->id,
            'plex_season_id' => $season->id,
        ]);
    });

    it('rejects a duplicate _plex_ratingKey under the same server on episodes', function (): void {
        // Arrange
        $season = PlexSeason::factory()->create();
        PlexEpisode::factory()->create([
            'plex_server_id' => $season->plex_server_id,
            'plex_show_id' => $season->plex_show_id,
            'plex_season_id' => $season->id,
            '_plex_ratingKey' => '99',
        ]);

        // Act & Assert
        expect(fn () => PlexEpisode::factory()->create([
            'plex_server_id' => $season->plex_server_id,
            'plex_show_id' => $season->plex_show_id,
            'plex_season_id' => $season->id,
            '_plex_ratingKey' => '99',
        ]))->toThrow(QueryException::class);
    });
});

describe('cascade deletes', function (): void {
    it('deletes a shows seasons and episodes when the show is deleted', function (): void {
        // Arrange
        $show = PlexShow::factory()->create();
        $season = PlexSeason::factory()->create([
            'plex_server_id' => $show->plex_server_id,
            'plex_show_id' => $show->id,
        ]);
        $episode = PlexEpisode::factory()->create([
            'plex_server_id' => $show->plex_server_id,
            'plex_show_id' => $show->id,
            'plex_season_id' => $season->id,
        ]);

        // Act
        $show->delete();

        // Assert
        $this->assertDatabaseMissing('plex_seasons', ['id' => $season->id]);
        $this->assertDatabaseMissing('plex_episodes', ['id' => $episode->id]);
    });

    it('deletes a seasons episodes when the season is deleted', function (): void {
        // Arrange
        $season = PlexSeason::factory()->create();
        $episode = PlexEpisode::factory()->create([
            'plex_server_id' => $season->plex_server_id,
            'plex_show_id' => $season->plex_show_id,
            'plex_season_id' => $season->id,
        ]);

        // Act
        $season->delete();

        // Assert
        $this->assertDatabaseMissing('plex_episodes', ['id' => $episode->id]);
    });
});
