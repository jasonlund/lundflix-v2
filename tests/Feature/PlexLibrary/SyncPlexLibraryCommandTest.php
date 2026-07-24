<?php

declare(strict_types=1);

use App\Domains\PlexLibrary\Models\PlexLibrary;
use App\Domains\PlexLibrary\Models\PlexMovie;
use App\Domains\PlexLibrary\Models\PlexServer;
use App\Domains\PlexLibrary\Models\PlexShow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| plex:sync — incremental crawl command slice
|--------------------------------------------------------------------------
| The command reconciles the top level (server, libraries, movies, shows) on
| EVERY run, but crawls episodes ONLY for the changed set that
| ReconcilePlexShows::handle() returns — new shows, or shows whose stored
| _plex_updatedAt / _plex_leafCount moved. Every outbound Plex call is faked
| at the wire by host-agnostic path pattern (Http::preventStrayRequests is
| global). This slice asserts the observable end-state and the per-show crawl
| requests only — never per-row identity, which the reconcilers own in their
| own tests. The shared fake lives in fakePlexSeedCrawl().
|
| A show is arranged "unchanged" by pre-seeding a PlexShow on the SAME server +
| ratingKey whose _plex_updatedAt (rendered via Date::createFromTimestamp, the
| production path) AND _plex_leafCount both match the fixture — the exact pair
| ReconcilePlexShows::isChanged() compares on. UpsertPlexServer and
| ReconcilePlexLibraries updateOrCreate on natural keys, so the pre-seeded
| ancestor chain (matching clientIdentifier / library key) is reused, keeping
| ids stable so the show's server+ratingKey match holds.
|
| Fixtures (byte-exact real captures, reused across the crawl):
|   tests/Fixtures/Common/plex/resources.json — 3 server resources; resource 0
|     clientIdentifier servermachineidentifier000000000 with best direct-https
|     uri https://203-0-113-2.servermachineidentifier000000000.plex.direct:6022.
|   tests/Fixtures/PlexLibrary/plex/sections.json — MediaContainer.Directory of
|     2 entries: {key:"1",type:"movie"} and {key:"2",type:"show"}.
|   tests/Fixtures/PlexLibrary/plex/section_movie_all_includeGuids.json — real
|     capture of 3 movies (ratingKeys 26278, 36080, 32202); totalSize 53, so the
|     pager is faked with an empty trailing page to terminate the walk.
|   tests/Fixtures/PlexLibrary/plex/section_show_all_includeGuids.json — real
|     capture of 3 shows: 34112 (leafCount 24, updatedAt 1782985591), 27520
|     (leafCount 4, updatedAt 1784194023), 32204 (leafCount 16, updatedAt
|     1782552566); totalSize 35, likewise terminated with an empty trailing page.
|   tests/Fixtures/PlexLibrary/plex/show_children_seasons.json — one season
|     Metadata row; reused for every show's /children fetch.
|   tests/Fixtures/PlexLibrary/plex/show_allLeaves_episodes.json — 24 episode
|     Metadata rows (show 34112); reused for every show's /allLeaves fetch.
*/

it('reconciles the top level on every run', function (): void {
    // Arrange
    fakePlexSeedCrawl();

    // Act
    $this->artisan('plex:sync')->run();

    // Assert
    expect(PlexServer::query()->count())->toBe(1);
    expect(PlexLibrary::query()->count())->toBe(2);
    expect(PlexMovie::query()->count())->toBe(3);
    expect(PlexShow::query()->count())->toBe(3);
});

it('crawls episodes only for changed shows', function (): void {
    // Arrange
    fakePlexSeedCrawl();
    seedUnchangedShow('27520', 4, 1784194023);

    // Act
    $this->artisan('plex:sync')->run();

    // Assert
    Http::assertSent(fn ($request): bool => Str::contains((string) $request->url(), '/library/metadata/34112/allLeaves'));
    Http::assertNotSent(fn ($request): bool => Str::contains((string) $request->url(), '/library/metadata/27520/allLeaves'));
    Http::assertNotSent(fn ($request): bool => Str::contains((string) $request->url(), '/library/metadata/27520/children'));
});

it('skips the episode crawl when nothing changed', function (): void {
    // Arrange
    fakePlexSeedCrawl();
    seedUnchangedShow('34112', 24, 1782985591);
    seedUnchangedShow('27520', 4, 1784194023);
    seedUnchangedShow('32204', 16, 1782552566);

    // Act
    $this->artisan('plex:sync')->run();

    // Assert
    Http::assertNotSent(fn ($request): bool => Str::contains((string) $request->url(), '/allLeaves'));
    Http::assertNotSent(fn ($request): bool => Str::contains((string) $request->url(), '/children'));
});

/**
 * Pre-seed the server, its show library, and one PlexShow whose stored
 * _plex_updatedAt / _plex_leafCount match the fixture exactly, so the run
 * reuses the ancestor chain (updateOrCreate on natural keys) and
 * ReconcilePlexShows sees the show as unchanged. The server + library are
 * resolved by their natural keys (firstOrCreate), so repeated calls in one
 * test share one ancestor chain while each test starts from a rolled-back DB.
 */
function seedUnchangedShow(string $ratingKey, int $leafCount, int $updatedAtEpoch): void
{
    $server = PlexServer::query()->firstOrCreate(
        ['_plex_clientIdentifier' => 'servermachineidentifier000000000'],
        PlexServer::factory()->raw(['_plex_clientIdentifier' => 'servermachineidentifier000000000']),
    );

    $library = PlexLibrary::query()->firstOrCreate(
        ['plex_server_id' => $server->id, '_plex_key' => '2'],
        PlexLibrary::factory()->raw(['plex_server_id' => $server->id, '_plex_key' => '2', '_plex_type' => 'show']),
    );

    PlexShow::factory()->create([
        'plex_server_id' => $library->plex_server_id,
        'plex_library_id' => $library->id,
        '_plex_ratingKey' => $ratingKey,
        '_plex_leafCount' => $leafCount,
        '_plex_updatedAt' => Date::createFromTimestamp($updatedAtEpoch),
    ]);
}
