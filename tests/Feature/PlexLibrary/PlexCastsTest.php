<?php

declare(strict_types=1);

use App\Domains\PlexLibrary\Models\PlexEpisode;
use App\Domains\PlexLibrary\Models\PlexMovie;
use App\Domains\PlexLibrary\Models\PlexSeason;
use App\Domains\PlexLibrary\Models\PlexShow;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

describe('plex model attribute casts', function (): void {
    it('reads a plex movie _plex_addedAt back as a CarbonImmutable from an epoch-seconds int', function (): void {
        // Arrange
        $movie = PlexMovie::factory()->create(['_plex_addedAt' => 1_700_000_000]);

        // Act
        $addedAt = $movie->fresh()->_plex_addedAt;

        // Assert
        expect($addedAt)->toBeInstanceOf(CarbonImmutable::class);
        expect($addedAt->timestamp)->toBe(1_700_000_000);
    });

    it('reads a plex movie _plex_updatedAt back as a CarbonImmutable from an epoch-seconds int', function (): void {
        // Arrange
        $movie = PlexMovie::factory()->create(['_plex_updatedAt' => 1_700_000_050]);

        // Act
        $updatedAt = $movie->fresh()->_plex_updatedAt;

        // Assert
        expect($updatedAt)->toBeInstanceOf(CarbonImmutable::class);
        expect($updatedAt->timestamp)->toBe(1_700_000_050);
    });

    it('reads a plex movie _plex_guids back as an array', function (): void {
        // Arrange
        $guids = [['id' => 'imdb://tt0111161'], ['id' => 'tmdb://278']];
        $movie = PlexMovie::factory()->create();
        DB::table('plex_movies')->where('id', $movie->id)->update(['_plex_guids' => json_encode($guids)]);

        // Act
        $stored = $movie->fresh()->_plex_guids;

        // Assert
        expect($stored)->toBeArray();
        expect($stored)->toBe($guids);
    });

    it('reads a plex episode _plex_guids back as an array', function (): void {
        // Arrange
        $guids = [['id' => 'imdb://tt0959621'], ['id' => 'tvdb://4127127']];
        $episode = PlexEpisode::factory()->create();
        DB::table('plex_episodes')->where('id', $episode->id)->update(['_plex_guids' => json_encode($guids)]);

        // Act
        $stored = $episode->fresh()->_plex_guids;

        // Assert
        expect($stored)->toBeArray();
        expect($stored)->toBe($guids);
    });

    it('reads plex show crosswalk ids back as integers', function (): void {
        // Arrange
        $show = PlexShow::factory()->create(['_imdb_id' => 'tt0285331', '_tmdb_id' => '1408', '_tvdb_id' => '73255']);

        // Act
        $fresh = $show->fresh();

        // Assert
        expect($fresh->_tmdb_id)->toBe(1408);
        expect($fresh->_tvdb_id)->toBe(73255);
        expect($fresh->_imdb_id)->toBe('tt0285331');
    });

    it('reads plex season plex timestamps back as CarbonImmutable from epoch-seconds ints', function (): void {
        // Arrange
        $season = PlexSeason::factory()->create(['_plex_addedAt' => 1_700_000_000, '_plex_updatedAt' => 1_700_000_050]);

        // Act
        $fresh = $season->fresh();

        // Assert
        expect($fresh->_plex_addedAt)->toBeInstanceOf(CarbonImmutable::class);
        expect($fresh->_plex_addedAt->timestamp)->toBe(1_700_000_000);
        expect($fresh->_plex_updatedAt)->toBeInstanceOf(CarbonImmutable::class);
        expect($fresh->_plex_updatedAt->timestamp)->toBe(1_700_000_050);
    });

    it('reads a plex season _tvdb_id back as an integer', function (): void {
        // Arrange
        $season = PlexSeason::factory()->create(['_tvdb_id' => '618570']);

        // Act
        $tvdbId = $season->fresh()->_tvdb_id;

        // Assert
        expect($tvdbId)->toBe(618570);
    });

    it('reads plex episode plex timestamps back as CarbonImmutable from epoch-seconds ints', function (): void {
        // Arrange
        $episode = PlexEpisode::factory()->create(['_plex_addedAt' => 1_700_000_000, '_plex_updatedAt' => 1_700_000_050]);

        // Act
        $fresh = $episode->fresh();

        // Assert
        expect($fresh->_plex_addedAt)->toBeInstanceOf(CarbonImmutable::class);
        expect($fresh->_plex_addedAt->timestamp)->toBe(1_700_000_000);
        expect($fresh->_plex_updatedAt)->toBeInstanceOf(CarbonImmutable::class);
        expect($fresh->_plex_updatedAt->timestamp)->toBe(1_700_000_050);
    });

    it('reads plex episode crosswalk ids back as integers', function (): void {
        // Arrange
        $episode = PlexEpisode::factory()->create(['_imdb_id' => 'tt0959621', '_tmdb_id' => '62161', '_tvdb_id' => '4127127']);

        // Act
        $fresh = $episode->fresh();

        // Assert
        expect($fresh->_tmdb_id)->toBe(62161);
        expect($fresh->_tvdb_id)->toBe(4127127);
        expect($fresh->_imdb_id)->toBe('tt0959621');
    });
});
