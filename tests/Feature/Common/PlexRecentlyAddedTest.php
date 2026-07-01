<?php

declare(strict_types=1);

use App\Domains\Common\Services\PlexApiService;
use Illuminate\Support\Facades\Http;

/*
|--------------------------------------------------------------------------
| Plex API service — recently added (model-free uri + token) slice
|--------------------------------------------------------------------------
| getRecentlyAdded($uri, $accessToken, $limit) lists a server's library
| sections (GET {uri}/library/sections), then for each movie/show section
| fetches its recently-added feed (GET {uri}/library/sections/{key}/recentlyAdded)
| and merges the items. Show sections request type=4 (episodes); movie sections
| send no type. Non movie/show sections (e.g. music/artist) are skipped. The
| paging window comes from $limit via X-Plex-Container-Start/Size. An empty uri
| or token short-circuits to [] without any HTTP.
|
| Fixtures:
|   library_sections.json — real capture; MediaContainer.Directory has a movie
|     section (key 1) and a show section (key 2).
|   recently_added_movie.json — real {uri}/library/sections/1/recentlyAdded feed;
|     3 items, each type=movie.
|   recently_added_show.json — real {uri}/library/sections/2/recentlyAdded feed;
|     3 items, each type=episode.
|   library_sections_with_music.json — SYNTHETIC: the real sections plus a third
|     music section {key 3, type artist}; a non movie/show section real data here
|     doesn't conveniently provide, so the skip can be exercised.
*/

it('merges recently added items from every movie and show section', function (): void {
    // Section-specific feeds listed before the bare sections pattern (first match wins).
    // Arrange
    $uri = 'https://srv.plex.direct:32400';
    Http::fake([
        '*srv.plex.direct*/library/sections/1/recentlyAdded*' => Http::response(fixtureBytes('Common/plex/recently_added_movie.json')),
        '*srv.plex.direct*/library/sections/2/recentlyAdded*' => Http::response(fixtureBytes('Common/plex/recently_added_show.json')),
        '*srv.plex.direct*/library/sections*' => Http::response(fixtureBytes('Common/plex/library_sections.json')),
    ]);

    // Act
    $result = resolve(PlexApiService::class)->getRecentlyAdded($uri, 'tok', 50);

    // Assert
    expect($result)->toBeArray()
        ->and($result)->toHaveCount(6)
        ->and(collect($result)->pluck('type')->all())->toContain('movie')
        ->and(collect($result)->pluck('type')->all())->toContain('episode');
});

it('requests type=4 for show sections and no type for movie sections', function (): void {
    // Arrange
    $uri = 'https://srv.plex.direct:32400';
    Http::fake([
        '*srv.plex.direct*/library/sections/1/recentlyAdded*' => Http::response(fixtureBytes('Common/plex/recently_added_movie.json')),
        '*srv.plex.direct*/library/sections/2/recentlyAdded*' => Http::response(fixtureBytes('Common/plex/recently_added_show.json')),
        '*srv.plex.direct*/library/sections*' => Http::response(fixtureBytes('Common/plex/library_sections.json')),
    ]);

    // Act
    resolve(PlexApiService::class)->getRecentlyAdded($uri, 'tok', 50);

    // Inspect the exact parsed query params, not URL substrings.
    // Assert
    Http::assertSent(function ($r): bool {
        parse_str((string) parse_url((string) $r->url(), PHP_URL_QUERY), $query);

        return str_contains((string) $r->url(), '/library/sections/2/recentlyAdded')
            && ($query['type'] ?? null) === '4';
    });
    Http::assertSent(function ($r): bool {
        parse_str((string) parse_url((string) $r->url(), PHP_URL_QUERY), $query);

        return str_contains((string) $r->url(), '/library/sections/1/recentlyAdded')
            && ! array_key_exists('type', $query);
    });
});

it('skips sections that are not movie or show', function (): void {
    // Section 3 (music) is deliberately not faked; reaching it would stray-error.
    // Arrange
    $uri = 'https://srv.plex.direct:32400';
    Http::fake([
        '*srv.plex.direct*/library/sections/1/recentlyAdded*' => Http::response(fixtureBytes('Common/plex/recently_added_movie.json')),
        '*srv.plex.direct*/library/sections/2/recentlyAdded*' => Http::response(fixtureBytes('Common/plex/recently_added_show.json')),
        '*srv.plex.direct*/library/sections*' => Http::response(fixtureBytes('Common/plex/library_sections_with_music.json')),
    ]);

    // Act
    resolve(PlexApiService::class)->getRecentlyAdded($uri, 'tok');

    // Assert
    Http::assertNotSent(fn ($r): bool => str_contains((string) $r->url(), '/library/sections/3/'));
});

it('returns an empty array for an empty uri', function (): void {
    // Arrange
    // no fakes; any HTTP would stray-error.

    // Act
    $result = resolve(PlexApiService::class)->getRecentlyAdded('', 'tok');

    // Assert
    expect($result)->toBe([]);
});

it('returns an empty array for an empty token', function (): void {
    // Arrange
    $uri = 'https://srv.plex.direct:32400';

    // Act
    $result = resolve(PlexApiService::class)->getRecentlyAdded($uri, '');

    // Assert
    expect($result)->toBe([]);
});

it('derives the container window from the limit', function (): void {
    // Arrange
    $uri = 'https://srv.plex.direct:32400';
    Http::fake([
        '*srv.plex.direct*/library/sections/1/recentlyAdded*' => Http::response(fixtureBytes('Common/plex/recently_added_movie.json')),
        '*srv.plex.direct*/library/sections/2/recentlyAdded*' => Http::response(fixtureBytes('Common/plex/recently_added_show.json')),
        '*srv.plex.direct*/library/sections*' => Http::response(fixtureBytes('Common/plex/library_sections.json')),
    ]);

    // Act
    resolve(PlexApiService::class)->getRecentlyAdded($uri, 'tok', 10);

    // Assert
    Http::assertSent(fn ($r): bool => str_contains((string) $r->url(), '/recentlyAdded')
        && (data_get($r->data(), 'X-Plex-Container-Start') == 0 || str_contains((string) $r->url(), 'Container-Start=0'))
        && (data_get($r->data(), 'X-Plex-Container-Size') == 10 || str_contains((string) $r->url(), 'Container-Size=10')));
});
