<?php

declare(strict_types=1);

use App\Domains\PlexLibrary\Models\PlexEpisode;
use App\Domains\PlexLibrary\Models\PlexMovie;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

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
