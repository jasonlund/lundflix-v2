<?php

declare(strict_types=1);

use App\Domains\Catalog\Models\Episode;
use App\Domains\Catalog\Models\Movie;
use App\Domains\Catalog\Models\Season;
use App\Domains\Catalog\Models\Show;
use App\Domains\PlexLibrary\Models\PlexEpisode;
use App\Domains\PlexLibrary\Models\PlexMovie;
use App\Domains\PlexLibrary\Models\PlexSeason;
use App\Domains\PlexLibrary\Models\PlexShow;

it('resolves the catalog movie sharing its _tmdb_id', function (): void {
    // Arrange
    $movie = Movie::factory()->create(['_tmdb_id' => 550]);
    $plexMovie = PlexMovie::factory()->create(['_tmdb_id' => 550]);

    // Act
    $related = $plexMovie->movie;

    // Assert
    expect($related)->toBeInstanceOf(Movie::class)
        ->and($related->getKey())->toBe($movie->getKey())
        ->and($related->_tmdb_id)->toBe(550);
});

it('resolves the catalog show sharing its _tvdb_id', function (): void {
    // Arrange
    $show = Show::factory()->withTvdb()->create(['_tvdb_id' => 121361]);
    $plexShow = PlexShow::factory()->create(['_tvdb_id' => 121361]);

    // Act
    $related = $plexShow->show;

    // Assert
    expect($related)->toBeInstanceOf(Show::class)
        ->and($related->getKey())->toBe($show->getKey())
        ->and($related->_tvdb_id)->toBe(121361);
});

it('resolves the catalog season sharing its _tvdb_id', function (): void {
    // Arrange
    $season = Season::factory()->create(['_tvdb_id' => 33639]);
    $plexSeason = PlexSeason::factory()->create(['_tvdb_id' => 33639]);

    // Act
    $related = $plexSeason->season;

    // Assert
    expect($related)->toBeInstanceOf(Season::class)
        ->and($related->getKey())->toBe($season->getKey())
        ->and($related->_tvdb_id)->toBe(33639);
});

it('resolves the catalog episode sharing its _tvdb_id', function (): void {
    // Arrange
    $episode = Episode::factory()->create(['_tvdb_id' => 3254641]);
    $plexEpisode = PlexEpisode::factory()->create(['_tvdb_id' => 3254641]);

    // Act
    $related = $plexEpisode->episode;

    // Assert
    expect($related)->toBeInstanceOf(Episode::class)
        ->and($related->getKey())->toBe($episode->getKey())
        ->and($related->_tvdb_id)->toBe(3254641);
});

it('returns its own plex seasons via the seasons hasMany', function (): void {
    // Arrange
    $plexShow = PlexShow::factory()->create();
    $season = PlexSeason::factory()->create([
        'plex_server_id' => $plexShow->plex_server_id,
        'plex_show_id' => $plexShow->id,
    ]);

    // Act
    $seasons = $plexShow->seasons;

    // Assert
    expect($seasons->pluck('id')->all())->toContain($season->id);
});

it('returns its own plex episodes via the episodes hasMany', function (): void {
    // Arrange
    $plexShow = PlexShow::factory()->create();
    $episode = PlexEpisode::factory()->create([
        'plex_server_id' => $plexShow->plex_server_id,
        'plex_show_id' => $plexShow->id,
    ]);

    // Act
    $episodes = $plexShow->episodes;

    // Assert
    expect($episodes->pluck('id')->all())->toContain($episode->id);
});
