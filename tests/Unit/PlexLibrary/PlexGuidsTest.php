<?php

declare(strict_types=1);

use App\Domains\PlexLibrary\Support\PlexGuids;

/*
 * Fixtures are byte-exact single-`Metadata` slices carved verbatim from real Plex
 * captures — `MediaContainer.Metadata[0]` of
 * `.context/plex-captures/section_{movie,show}_all_includeGuids.json` (movie/show),
 * `show_children_seasons.json` (season) and `show_allLeaves_episodes.json`
 * (episode), minified to one line. Each retains its real `Guid[]` crosswalk array
 * and its `plex://` top-level `guid`, untouched. The season slice carries only a
 * lone `tvdb://` guid — proving imdb/tmdb null-retention.
 *
 * Slice-3 (first-wins ordering) inputs are SYNTHETIC splices: the real decoded
 * episode item cloned inline with its `Guid[]` and/or top-level
 * `guid`/`parentGuid`/`grandparentGuid` fields mutated. Real Plex top-level
 * fields are ALWAYS `plex://` (they contribute no crosswalk id), so the
 * Guid[]-then-top-level fallback and the first-non-null-wins collision are only
 * exercisable with constructed input — real data can't produce the collision.
 *
 * Slice-4 (malformed variants → null) inputs are likewise SYNTHETIC garbage
 * splices: the real decoded movie item cloned inline with ONE `Guid[]` entry
 * mutated into an overflowing / slug-appended / prefix-missing / non-digit /
 * empty-remainder value (plus a wrong-scheme `anidb://` entry) — real Plex never
 * emits these. Each retains two valid sibling ids, so the case both pins the
 * null target and proves the siblings still extract. These characterize the
 * boundaries `App\Domains\Common\Support\SourceId` already enforces (the id
 * normalizers `PlexGuids` routes through), guarding against a future inline
 * normalizer in `PlexGuids` that diverges from `SourceId`.
 */

it('extracts all three normalized crosswalk ids from a real movie item', function (): void {
    // Arrange
    $metadata = json_decode(fixtureBytes('PlexLibrary/plex/movie.json'), true);

    // Act
    $actual = PlexGuids::extract($metadata);

    // Assert
    expect($actual['imdb'])->toBe('tt8368368');
    expect($actual['tmdb'])->toBe(1182047);
    expect($actual['tvdb'])->toBe(351923);
});

it('extracts all three normalized crosswalk ids from a real show item', function (): void {
    // Arrange
    $metadata = json_decode(fixtureBytes('PlexLibrary/plex/show.json'), true);

    // Act
    $actual = PlexGuids::extract($metadata);

    // Assert
    expect($actual['imdb'])->toBe('tt0285331');
    expect($actual['tmdb'])->toBe(1973);
    expect($actual['tvdb'])->toBe(76290);
});

it('extracts the lone tvdb id and retains null imdb/tmdb from a real season item', function (): void {
    // Arrange
    $metadata = json_decode(fixtureBytes('PlexLibrary/plex/season.json'), true);

    // Act
    $actual = PlexGuids::extract($metadata);

    // Assert
    expect($actual['tvdb'])->toBe(10064);
    expect($actual['imdb'])->toBeNull();
    expect($actual['tmdb'])->toBeNull();
});

it('extracts all three normalized crosswalk ids from a real episode item', function (): void {
    // Arrange
    $metadata = json_decode(fixtureBytes('PlexLibrary/plex/episode.json'), true);

    // Act
    $actual = PlexGuids::extract($metadata);

    // Assert
    expect($actual['imdb'])->toBe('tt0502205');
    expect($actual['tmdb'])->toBe(134418);
    expect($actual['tvdb'])->toBe(189279);
});

it('falls back to a top-level field for a scheme absent from Guid[]', function (): void {
    // Arrange
    // synthetic: real episode with its tmdb Guid[] entry dropped and a
    // constructed top-level `guid` supplying tmdb (real fields are plex://)
    $episode = json_decode(fixtureBytes('PlexLibrary/plex/episode.json'), true);
    $episode['Guid'] = [
        ['id' => 'imdb://tt0502205'],
        ['id' => 'tvdb://189279'],
    ];
    $episode['guid'] = 'tmdb://1973';

    // Act
    $actual = PlexGuids::extract($episode);

    // Assert
    expect($actual['tmdb'])->toBe(1973);
    expect($actual['imdb'])->toBe('tt0502205');
    expect($actual['tvdb'])->toBe(189279);
});

it('prefers a Guid[] id over a same-scheme top-level field', function (): void {
    // Arrange
    // synthetic: real episode's own imdb Guid[] id plus a constructed
    // parentGuid carrying a different (parent/show) imdb id
    $episode = json_decode(fixtureBytes('PlexLibrary/plex/episode.json'), true);
    $episode['parentGuid'] = 'imdb://tt9999999';

    // Act
    $actual = PlexGuids::extract($episode);

    // Assert
    expect($actual['imdb'])->toBe('tt0502205');
});

it('keeps the first non-null id when a later same-scheme entry is malformed', function (): void {
    // Arrange
    // synthetic: valid tmdb entry FIRST, slug-appended garbage SECOND — proves
    // the leading valid id survives instead of being clobbered by trailing null
    $episode = json_decode(fixtureBytes('PlexLibrary/plex/episode.json'), true);
    $episode['Guid'] = [
        ['id' => 'imdb://tt0502205'],
        ['id' => 'tmdb://1973'],
        ['id' => 'tmdb://1335814-silvio-santos'],
        ['id' => 'tvdb://189279'],
    ];

    // Act
    $actual = PlexGuids::extract($episode);

    // Assert
    expect($actual['tmdb'])->toBe(1973);
    expect($actual['imdb'])->toBe('tt0502205');
    expect($actual['tvdb'])->toBe(189279);
});

it('nulls an overflowing tmdb id while its siblings still extract', function (): void {
    // Arrange
    // synthetic: real movie with tmdb mutated to > 4_294_967_295 (unsigned-int overflow)
    $movie = json_decode(fixtureBytes('PlexLibrary/plex/movie.json'), true);
    $movie['Guid'] = [
        ['id' => 'imdb://tt8368368'],
        ['id' => 'tmdb://99999999999999'],
        ['id' => 'tvdb://351923'],
    ];

    // Act
    $actual = PlexGuids::extract($movie);

    // Assert
    expect($actual['tmdb'])->toBeNull();
    expect($actual['imdb'])->toBe('tt8368368');
    expect($actual['tvdb'])->toBe(351923);
});

it('nulls a slug-appended tmdb id while its siblings still extract', function (): void {
    // Arrange
    // synthetic: real movie with tmdb mutated to a slug-appended value
    $movie = json_decode(fixtureBytes('PlexLibrary/plex/movie.json'), true);
    $movie['Guid'] = [
        ['id' => 'imdb://tt8368368'],
        ['id' => 'tmdb://1335814-silvio-santos'],
        ['id' => 'tvdb://351923'],
    ];

    // Act
    $actual = PlexGuids::extract($movie);

    // Assert
    expect($actual['tmdb'])->toBeNull();
    expect($actual['imdb'])->toBe('tt8368368');
    expect($actual['tvdb'])->toBe(351923);
});

it('nulls an imdb id missing its tt prefix while its siblings still extract', function (): void {
    // Arrange
    // synthetic: real movie with imdb mutated to a bare-digit value (no `tt`)
    $movie = json_decode(fixtureBytes('PlexLibrary/plex/movie.json'), true);
    $movie['Guid'] = [
        ['id' => 'imdb://12345'],
        ['id' => 'tmdb://1182047'],
        ['id' => 'tvdb://351923'],
    ];

    // Act
    $actual = PlexGuids::extract($movie);

    // Assert
    expect($actual['imdb'])->toBeNull();
    expect($actual['tmdb'])->toBe(1182047);
    expect($actual['tvdb'])->toBe(351923);
});

it('nulls a non-digit tvdb id while its siblings still extract', function (): void {
    // Arrange
    // synthetic: real movie with tvdb mutated to a non-numeric value
    $movie = json_decode(fixtureBytes('PlexLibrary/plex/movie.json'), true);
    $movie['Guid'] = [
        ['id' => 'imdb://tt8368368'],
        ['id' => 'tmdb://1182047'],
        ['id' => 'tvdb://abc'],
    ];

    // Act
    $actual = PlexGuids::extract($movie);

    // Assert
    expect($actual['tvdb'])->toBeNull();
    expect($actual['imdb'])->toBe('tt8368368');
    expect($actual['tmdb'])->toBe(1182047);
});

it('ignores an empty-remainder tmdb and a wrong-scheme entry, surfacing no stray key', function (): void {
    // Arrange
    // synthetic: real movie with tmdb mutated to an empty remainder plus an
    // unrecognized anidb:// scheme — neither contributes a key
    $movie = json_decode(fixtureBytes('PlexLibrary/plex/movie.json'), true);
    $movie['Guid'] = [
        ['id' => 'imdb://tt8368368'],
        ['id' => 'tmdb://'],
        ['id' => 'tvdb://351923'],
        ['id' => 'anidb://999'],
    ];

    // Act
    $actual = PlexGuids::extract($movie);

    // Assert
    expect($actual)->toBe(['imdb' => 'tt8368368', 'tmdb' => null, 'tvdb' => 351923]);
});

it('surfaces only imdb/tmdb/tvdb keys and never the plex:// top-level guid', function (): void {
    // Arrange
    $metadata = json_decode(fixtureBytes('PlexLibrary/plex/movie.json'), true);

    // Act
    $actual = PlexGuids::extract($metadata);

    // Assert
    expect(array_keys($actual))->toBe(['imdb', 'tmdb', 'tvdb']);
});
