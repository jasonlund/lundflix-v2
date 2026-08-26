<?php

declare(strict_types=1);

use App\Domains\PlexLibrary\Actions\SelectRipeAnnouncements;
use App\Domains\PlexLibrary\Models\PlexEpisode;
use App\Domains\PlexLibrary\Models\PlexMovie;
use App\Domains\PlexLibrary\Models\PlexSeason;
use App\Domains\PlexLibrary\Models\PlexShow;

/*
 * Pending movies (announced_at IS NULL) form ONE bucket that ripens either after
 * the quiet period or at the hard deadline, whichever comes first.
 *
 * Pending episodes are bucketed PER SHOW instead: each show's own oldest/newest
 * arrivals decide that show's ripeness, so a show still receiving episodes keeps
 * waiting while an unrelated quiet show ships.
 *
 * Arrival is the row's `created_at` — set explicitly on each factory row rather
 * than by travelling the clock, so every case states its own arithmetic against
 * the windows configured in its Arrange (120s/300s quiet / 900s deadline).
 */

describe('handle() movie bucket', function (): void {
    it('returns the ids of pending movies once the bucket has been quiet past the debounce window', function (): void {
        // Arrange
        config()->set('services.plex.announce.movie_debounce_seconds', 120);
        config()->set('services.plex.announce.hard_deadline_seconds', 900);
        $oldest = PlexMovie::factory()->create(['created_at' => now()->subSeconds(300), 'announced_at' => null]);
        $newest = PlexMovie::factory()->create(['created_at' => now()->subSeconds(121), 'announced_at' => null]);

        // Act
        $ripe = (new SelectRipeAnnouncements)->handle();

        // Assert
        expect($ripe->movieIds)->toEqualCanonicalizing([$oldest->id, $newest->id]);
    });

    it('returns nothing while the newest pending movie is still inside the window', function (): void {
        // Arrange
        config()->set('services.plex.announce.movie_debounce_seconds', 120);
        config()->set('services.plex.announce.hard_deadline_seconds', 900);
        PlexMovie::factory()->create(['created_at' => now()->subSeconds(200), 'announced_at' => null]);
        PlexMovie::factory()->create(['created_at' => now()->subSeconds(30), 'announced_at' => null]);

        // Act
        $ripe = (new SelectRipeAnnouncements)->handle();

        // Assert
        expect($ripe->movieIds)->toBe([]);
    });

    // The deadline arm flushes the WHOLE bucket, it does not cherry-pick the rows
    // that individually aged past the deadline — a library still dripping in movies
    // would otherwise never announce.
    it('returns the whole bucket at the hard deadline even though a movie arrived seconds ago', function (): void {
        // Arrange
        config()->set('services.plex.announce.movie_debounce_seconds', 120);
        config()->set('services.plex.announce.hard_deadline_seconds', 900);
        $stale = PlexMovie::factory()->create(['created_at' => now()->subSeconds(1000), 'announced_at' => null]);
        $justArrived = PlexMovie::factory()->create(['created_at' => now()->subSeconds(5), 'announced_at' => null]);

        // Act
        $ripe = (new SelectRipeAnnouncements)->handle();

        // Assert
        expect($ripe->movieIds)->toEqualCanonicalizing([$stale->id, $justArrived->id]);
    });

    it('excludes movies that were already announced', function (): void {
        // Arrange
        config()->set('services.plex.announce.movie_debounce_seconds', 120);
        config()->set('services.plex.announce.hard_deadline_seconds', 900);
        PlexMovie::factory()->create(['created_at' => now()->subSeconds(1000), 'announced_at' => now()->subSeconds(900)]);
        PlexMovie::factory()->create(['created_at' => now()->subSeconds(300), 'announced_at' => now()->subSeconds(120)]);

        // Act
        $ripe = (new SelectRipeAnnouncements)->handle();

        // Assert
        expect($ripe->movieIds)->toBe([]);
    });
});

describe('handle() per-show episode buckets', function (): void {
    it('returns a show\'s pending episode ids once that show has been quiet past the episode window', function (): void {
        // Arrange
        config()->set('services.plex.announce.episode_debounce_seconds', 300);
        config()->set('services.plex.announce.hard_deadline_seconds', 900);
        $season = PlexSeason::factory()->create();
        $oldest = PlexEpisode::factory()->create(['plex_season_id' => $season->id, 'created_at' => now()->subSeconds(600), 'announced_at' => null]);
        $newest = PlexEpisode::factory()->create(['plex_season_id' => $season->id, 'created_at' => now()->subSeconds(301), 'announced_at' => null]);

        // Act
        $ripe = (new SelectRipeAnnouncements)->handle();

        // Assert
        expect($ripe->episodeIds)->toEqualCanonicalizing([$oldest->id, $newest->id]);
    });

    it('returns nothing for a show that received an episode seconds ago', function (): void {
        // Arrange
        config()->set('services.plex.announce.episode_debounce_seconds', 300);
        config()->set('services.plex.announce.hard_deadline_seconds', 900);
        $season = PlexSeason::factory()->create();
        PlexEpisode::factory()->create(['plex_season_id' => $season->id, 'created_at' => now()->subSeconds(400), 'announced_at' => null]);
        PlexEpisode::factory()->create(['plex_season_id' => $season->id, 'created_at' => now()->subSeconds(10), 'announced_at' => null]);

        // Act
        $ripe = (new SelectRipeAnnouncements)->handle();

        // Assert
        expect($ripe->episodeIds)->toBe([]);
    });

    // The point of the slice: shows ripen independently. An implementation that
    // buckets every pending episode together — the way movies are bucketed — lets
    // the busy show's fresh arrival hold the quiet show hostage, or ships both.
    it('ripens a quiet show while a still-receiving show keeps waiting', function (): void {
        // Arrange
        config()->set('services.plex.announce.episode_debounce_seconds', 300);
        config()->set('services.plex.announce.hard_deadline_seconds', 900);
        $quietShow = PlexShow::factory()->create();
        $busyShow = PlexShow::factory()->create();
        $quietSeason = PlexSeason::factory()->create(['plex_show_id' => $quietShow->id]);
        $busySeason = PlexSeason::factory()->create(['plex_show_id' => $busyShow->id]);
        $quietEpisode = PlexEpisode::factory()->create(['plex_season_id' => $quietSeason->id, 'created_at' => now()->subSeconds(400), 'announced_at' => null]);
        PlexEpisode::factory()->create(['plex_season_id' => $busySeason->id, 'created_at' => now()->subSeconds(400), 'announced_at' => null]);
        PlexEpisode::factory()->create(['plex_season_id' => $busySeason->id, 'created_at' => now()->subSeconds(10), 'announced_at' => null]);

        // Act
        $ripe = (new SelectRipeAnnouncements)->handle();

        // Assert
        expect($ripe->episodeIds)->toBe([$quietEpisode->id]);
    });

    it('returns a still-receiving show once its oldest pending episode passes the hard deadline', function (): void {
        // Arrange
        config()->set('services.plex.announce.episode_debounce_seconds', 300);
        config()->set('services.plex.announce.hard_deadline_seconds', 900);
        $season = PlexSeason::factory()->create();
        $stale = PlexEpisode::factory()->create(['plex_season_id' => $season->id, 'created_at' => now()->subSeconds(1000), 'announced_at' => null]);
        $justArrived = PlexEpisode::factory()->create(['plex_season_id' => $season->id, 'created_at' => now()->subSeconds(5), 'announced_at' => null]);

        // Act
        $ripe = (new SelectRipeAnnouncements)->handle();

        // Assert
        expect($ripe->episodeIds)->toEqualCanonicalizing([$stale->id, $justArrived->id]);
    });

    it('excludes episodes that were already announced', function (): void {
        // Arrange
        config()->set('services.plex.announce.episode_debounce_seconds', 300);
        config()->set('services.plex.announce.hard_deadline_seconds', 900);
        $season = PlexSeason::factory()->create();
        PlexEpisode::factory()->create(['plex_season_id' => $season->id, 'created_at' => now()->subSeconds(1000), 'announced_at' => now()->subSeconds(900)]);
        PlexEpisode::factory()->create(['plex_season_id' => $season->id, 'created_at' => now()->subSeconds(600), 'announced_at' => now()->subSeconds(300)]);

        // Act
        $ripe = (new SelectRipeAnnouncements)->handle();

        // Assert
        expect($ripe->episodeIds)->toBe([]);
    });
});
