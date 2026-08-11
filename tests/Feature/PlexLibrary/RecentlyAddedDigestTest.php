<?php

declare(strict_types=1);

use App\Domains\Catalog\Models\Movie;
use App\Domains\Catalog\Models\Show;
use App\Domains\PlexLibrary\Models\PlexEpisode;
use App\Domains\PlexLibrary\Models\PlexMovie;
use App\Domains\PlexLibrary\Models\PlexSeason;
use App\Domains\PlexLibrary\Models\PlexShow;
use App\Domains\PlexLibrary\Support\RecentlyAddedDigest;

it('prefers the matched catalog title and year over the plex ones', function (): void {
    // Arrange
    Movie::factory()->create([
        '_tmdb_id' => 550,
        '_tmdb_title' => 'The Apprentice',
        '_tmdb_release_date' => '2024-10-11',
    ]);
    PlexMovie::factory()->create([
        '_tmdb_id' => 550,
        '_plex_title' => 'the apprentice 2024 1080p',
        '_plex_year' => 2023,
    ]);

    // Act
    $lines = RecentlyAddedDigest::lines(PlexMovie::query()->with('movie')->get(), collect());

    // Assert
    expect($lines)->toBe(['The Apprentice (2024)']);
});

it('falls back to the plex title and year when no catalog movie matches', function (): void {
    // Arrange
    PlexMovie::factory()->create([
        '_tmdb_id' => null,
        '_plex_title' => 'Nosferatu',
        '_plex_year' => 2024,
    ]);

    // Act
    $lines = RecentlyAddedDigest::lines(PlexMovie::query()->with('movie')->get(), collect());

    // Assert
    expect($lines)->toBe(['Nosferatu (2024)']);
});

it('renders a bare title when neither source has a year', function (): void {
    // Arrange
    Movie::factory()->create([
        '_tmdb_id' => 550,
        '_tmdb_title' => 'The Apprentice',
        '_tmdb_release_date' => null,
    ]);
    PlexMovie::factory()->create([
        '_tmdb_id' => 550,
        '_plex_title' => 'the apprentice 1080p',
        '_plex_year' => null,
    ]);

    // Act
    $lines = RecentlyAddedDigest::lines(PlexMovie::query()->with('movie')->get(), collect());

    // Assert
    expect($lines)->toBe(['The Apprentice']);
});

it('sorts the movie lines alphabetically', function (): void {
    // Arrange
    foreach (['Zodiac', 'Anora', 'Manhunter'] as $title) {
        PlexMovie::factory()->create([
            '_tmdb_id' => null,
            '_plex_title' => $title,
            '_plex_year' => 2024,
        ]);
    }

    // Act
    $lines = RecentlyAddedDigest::lines(PlexMovie::query()->with('movie')->get(), collect());

    // Assert
    expect($lines)->toBe(['Anora (2024)', 'Manhunter (2024)', 'Zodiac (2024)']);
});

// Escaping is not order-preserving: raw '<' precedes '>', but their escapes sort
// on the entity name, where 'gt' precedes 'lt'.
it('sorts the movie lines on the raw title rather than the escaped one', function (): void {
    // Arrange
    foreach (['A>Movie', 'A<Movie'] as $title) {
        PlexMovie::factory()->create([
            '_tmdb_id' => null,
            '_plex_title' => $title,
            '_plex_year' => 2024,
        ]);
    }

    // Act
    $lines = RecentlyAddedDigest::lines(PlexMovie::query()->with('movie')->get(), collect());

    // Assert
    expect($lines)->toBe(['A&lt;Movie (2024)', 'A&gt;Movie (2024)']);
});

// Every escape opens with '&', which sorts ahead of the digits a title can start
// with, so an escaped title would jump the whole numeric run it belongs after.
it('keeps an escaped title in the alphabetical place its raw title holds', function (): void {
    // Arrange
    foreach (['<Untitled> Project' => 2024, 'Fast & Furious' => 2009, '2 Fast 2 Furious' => 2003] as $title => $year) {
        PlexMovie::factory()->create([
            '_tmdb_id' => null,
            '_plex_title' => $title,
            '_plex_year' => $year,
        ]);
    }

    // Act
    $lines = RecentlyAddedDigest::lines(PlexMovie::query()->with('movie')->get(), collect());

    // Assert
    expect($lines)->toBe([
        '2 Fast 2 Furious (2003)',
        '&lt;Untitled&gt; Project (2024)',
        'Fast &amp; Furious (2009)',
    ]);
});

it('renders a lone new episode as a single season and episode number', function (): void {
    // Arrange
    $season = matchedShowSeason(
        catalogName: 'Severance',
        plexTitle: 'severance.2022.1080p',
        seasonNumber: 2,
        leafCount: 10,
    );
    PlexEpisode::factory()->create([
        'plex_season_id' => $season->id,
        '_plex_parentIndex' => 2,
        '_plex_index' => 4,
    ]);

    // Act
    $lines = RecentlyAddedDigest::lines(collect(), PlexEpisode::query()->with('plexShow.show')->get());

    // Assert
    expect($lines)->toBe(['Severance S02E04']);
});

it('collapses consecutive new episodes into one span', function (): void {
    // Arrange
    $season = matchedShowSeason(
        catalogName: 'Severance',
        plexTitle: 'severance.2022.1080p',
        seasonNumber: 2,
        leafCount: 24,
    );
    foreach (range(1, 12) as $number) {
        PlexEpisode::factory()->create([
            'plex_season_id' => $season->id,
            '_plex_parentIndex' => 2,
            '_plex_index' => $number,
        ]);
    }

    // Act
    $lines = RecentlyAddedDigest::lines(collect(), PlexEpisode::query()->with('plexShow.show')->get());

    // Assert
    expect($lines)->toBe(['Severance S02E01-E12']);
});

it('splits a gap in the episode numbers into separate spans', function (): void {
    // Arrange
    $season = matchedShowSeason(
        catalogName: 'Severance',
        plexTitle: 'severance.2022.1080p',
        seasonNumber: 2,
        leafCount: 24,
    );
    foreach ([1, 2, 3, 6] as $number) {
        PlexEpisode::factory()->create([
            'plex_season_id' => $season->id,
            '_plex_parentIndex' => 2,
            '_plex_index' => $number,
        ]);
    }

    // Act
    $lines = RecentlyAddedDigest::lines(collect(), PlexEpisode::query()->with('plexShow.show')->get());

    // Assert
    expect($lines)->toBe(['Severance S02E01-E03, S02E06']);
});

it('renders a season zero episode as an ordinary season', function (): void {
    // Arrange
    $season = matchedShowSeason(
        catalogName: 'Severance',
        plexTitle: 'severance.2022.1080p',
        seasonNumber: 0,
        leafCount: 5,
    );
    PlexEpisode::factory()->create([
        'plex_season_id' => $season->id,
        '_plex_parentIndex' => 0,
        '_plex_index' => 1,
    ]);

    // Act
    $lines = RecentlyAddedDigest::lines(collect(), PlexEpisode::query()->with('plexShow.show')->get());

    // Assert
    expect($lines)->toBe(['Severance S00E01']);
});

it('falls back to the plex show title when no catalog show matches', function (): void {
    // Arrange
    Show::factory()->withTvdb()->create([
        '_tvdb_id' => 9999,
        '_tvdb_name' => 'Andor',
    ]);
    $plexShow = PlexShow::factory()->create([
        '_tvdb_id' => null,
        '_plex_title' => 'andor.2022.2160p',
    ]);
    $season = PlexSeason::factory()->create([
        'plex_show_id' => $plexShow->id,
        '_plex_index' => 1,
        '_plex_leafCount' => 12,
    ]);
    PlexEpisode::factory()->create([
        'plex_season_id' => $season->id,
        '_plex_parentIndex' => 1,
        '_plex_index' => 1,
    ]);

    // Act
    $lines = RecentlyAddedDigest::lines(collect(), PlexEpisode::query()->with('plexShow.show')->get());

    // Assert
    expect($lines)->toBe(['andor.2022.2160p S01E01']);
});

it('collapses a season whose every episode is new to the bare season', function (): void {
    // Arrange
    $season = matchedShowSeason(
        catalogName: 'Severance',
        plexTitle: 'severance.2022.1080p',
        seasonNumber: 1,
        leafCount: 8,
    );
    foreach (range(1, 8) as $number) {
        PlexEpisode::factory()->create([
            'plex_season_id' => $season->id,
            '_plex_parentIndex' => 1,
            '_plex_index' => $number,
        ]);
    }

    // Act
    $lines = RecentlyAddedDigest::lines(collect(), PlexEpisode::query()->with('plexShow.show', 'plexSeason')->get());

    // Assert
    expect($lines)->toBe(['Severance S01']);
});

// ReconcilePlexEpisodes deliberately persists an episode whose season row hasn't
// arrived yet with a null season link, so the season's episode total is unknown
// — an unknown total must degrade to an explicit span rather than be guessed at.
it('renders an explicit span for an episode with no season row', function (): void {
    // Arrange
    $plexShow = PlexShow::factory()->create([
        '_tvdb_id' => null,
        '_plex_title' => 'Severance',
    ]);
    PlexEpisode::factory()->create([
        'plex_show_id' => $plexShow->id,
        'plex_server_id' => $plexShow->plex_server_id,
        'plex_season_id' => null,
        '_plex_parentIndex' => 1,
        '_plex_index' => 1,
    ]);

    // Act
    $lines = RecentlyAddedDigest::lines(collect(), PlexEpisode::query()->with('plexShow.show', 'plexSeason')->get());

    // Assert
    expect($lines)->toBe(['Severance S01E01']);
});

it('joins a show\'s seasons ascending on one line', function (): void {
    // Arrange
    $seasonOne = matchedShowSeason(
        catalogName: 'Severance',
        plexTitle: 'severance.2022.1080p',
        seasonNumber: 1,
        leafCount: 2,
    );
    $seasonTwo = PlexSeason::factory()->create([
        'plex_show_id' => $seasonOne->plex_show_id,
        '_plex_index' => 2,
        '_plex_leafCount' => 24,
    ]);
    // Created out of order so the ascending season sort is what the assertion pins.
    foreach (range(1, 12) as $number) {
        PlexEpisode::factory()->create([
            'plex_season_id' => $seasonTwo->id,
            '_plex_parentIndex' => 2,
            '_plex_index' => $number,
        ]);
    }
    foreach (range(1, 2) as $number) {
        PlexEpisode::factory()->create([
            'plex_season_id' => $seasonOne->id,
            '_plex_parentIndex' => 1,
            '_plex_index' => $number,
        ]);
    }

    // Act
    $lines = RecentlyAddedDigest::lines(collect(), PlexEpisode::query()->with('plexShow.show', 'plexSeason')->get());

    // Assert
    expect($lines)->toBe(['Severance S01, S02E01-E12']);
});

it('sorts the show lines alphabetically', function (): void {
    // Arrange
    // Created in reverse so the alphabetical sort is what the assertion pins.
    foreach (['Severance', 'Andor'] as $title) {
        $plexShow = PlexShow::factory()->create([
            '_tvdb_id' => null,
            '_plex_title' => $title,
        ]);
        $season = PlexSeason::factory()->create([
            'plex_show_id' => $plexShow->id,
            '_plex_index' => 1,
            '_plex_leafCount' => 9,
        ]);
        PlexEpisode::factory()->create([
            'plex_season_id' => $season->id,
            '_plex_parentIndex' => 1,
            '_plex_index' => 1,
        ]);
    }

    // Act
    $lines = RecentlyAddedDigest::lines(collect(), PlexEpisode::query()->with('plexShow.show', 'plexSeason')->get());

    // Assert
    expect($lines)->toBe(['Andor S01E01', 'Severance S01E01']);
});

it('lists every movie line before the show lines', function (): void {
    // Arrange
    // The movie sorts after the show alphabetically, so a single global sort
    // over all lines would flip them.
    PlexMovie::factory()->create([
        '_tmdb_id' => null,
        '_plex_title' => 'Zodiac',
        '_plex_year' => 2007,
    ]);
    $plexShow = PlexShow::factory()->create([
        '_tvdb_id' => null,
        '_plex_title' => 'Andor',
    ]);
    $season = PlexSeason::factory()->create([
        'plex_show_id' => $plexShow->id,
        '_plex_index' => 1,
        '_plex_leafCount' => 9,
    ]);
    PlexEpisode::factory()->create([
        'plex_season_id' => $season->id,
        '_plex_parentIndex' => 1,
        '_plex_index' => 1,
    ]);

    // Act
    $lines = RecentlyAddedDigest::lines(
        PlexMovie::query()->with('movie')->get(),
        PlexEpisode::query()->with('plexShow.show', 'plexSeason')->get(),
    );

    // Assert
    expect($lines)->toBe(['Zodiac (2007)', 'Andor S01E01']);
});

it('escapes an ampersand in a show name', function (): void {
    // Arrange
    $season = matchedShowSeason(
        catalogName: 'Law & Order',
        plexTitle: 'law.and.order.1990.1080p',
        seasonNumber: 1,
        leafCount: 22,
    );
    PlexEpisode::factory()->create([
        'plex_season_id' => $season->id,
        '_plex_parentIndex' => 1,
        '_plex_index' => 3,
    ]);

    // Act
    $lines = RecentlyAddedDigest::lines(collect(), PlexEpisode::query()->with('plexShow.show', 'plexSeason')->get());

    // Assert
    expect($lines)->toBe(['Law &amp; Order S01E03']);
});

// Slack parses <…> as markup and swallows it, so angle brackets that reach it raw
// erase the title they wrap — Plex hands them over from careless release metadata.
it('escapes angle brackets in a movie title', function (): void {
    // Arrange
    PlexMovie::factory()->create([
        '_tmdb_id' => null,
        '_plex_title' => '<Untitled> Project',
        '_plex_year' => 2024,
    ]);

    // Act
    $lines = RecentlyAddedDigest::lines(PlexMovie::query()->with('movie')->get(), collect());

    // Assert
    expect($lines)->toBe(['&lt;Untitled&gt; Project (2024)']);
});

it('leaves a title with no escapable character untouched', function (): void {
    // Arrange
    foreach (['Amélie' => 2001, "Rosemary's Baby" => 1968] as $title => $year) {
        PlexMovie::factory()->create([
            '_tmdb_id' => null,
            '_plex_title' => $title,
            '_plex_year' => $year,
        ]);
    }

    // Act
    $lines = RecentlyAddedDigest::lines(PlexMovie::query()->with('movie')->get(), collect());

    // Assert
    expect($lines)->toBe(['Amélie (2001)', "Rosemary's Baby (1968)"]);
});

/**
 * One season of a catalog Show that its Plex row resolves to — the crosswalked
 * trio (Show, PlexShow, PlexSeason) every matched-show case arranges. Callers
 * pass both names so the catalog-over-Plex precedence stays readable, and the
 * $leafCount that decides whether the episodes they go on to create make up the
 * whole season (equal) or only part of it (larger).
 */
function matchedShowSeason(string $catalogName, string $plexTitle, int $seasonNumber, int $leafCount): PlexSeason
{
    Show::factory()->withTvdb()->create([
        '_tvdb_id' => 4321,
        '_tvdb_name' => $catalogName,
    ]);
    $plexShow = PlexShow::factory()->create([
        '_tvdb_id' => 4321,
        '_plex_title' => $plexTitle,
    ]);

    return PlexSeason::factory()->create([
        'plex_show_id' => $plexShow->id,
        '_plex_index' => $seasonNumber,
        '_plex_leafCount' => $leafCount,
    ]);
}
