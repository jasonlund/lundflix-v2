<?php

declare(strict_types=1);

use App\Domains\PlexLibrary\Models\PlexEpisode;
use App\Domains\PlexLibrary\Models\PlexLibrary;
use App\Domains\PlexLibrary\Models\PlexMovie;
use App\Domains\PlexLibrary\Models\PlexServer;
use App\Domains\PlexLibrary\Models\PlexShow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\Console\Command\Command;

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
| The changed set is NOT the whole selection: a show also gets crawled while its
| episode crawl is behind, tracked by the app-owned episodes_synced_at watermark
| (stamped only after a show's episode reconcile returns). Without it a show
| whose /allLeaves fetch failed would be stranded — ReconcilePlexShows already
| wrote its incoming _plex_updatedAt/_plex_leafCount, so the next run sees no
| diff and would never retry it.
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

it('exits FAILURE when a show episode crawl failed', function (): void {
    // Arrange
    fakePlexSeedCrawl(failLeavesForRatingKey: '34112');

    // Act & Assert
    $this->artisan('plex:sync')->assertExitCode(Command::FAILURE);
});

it('stamps the episode watermark on every show it crawled', function (): void {
    // Arrange
    fakePlexSeedCrawl();

    // Act
    $this->artisan('plex:sync')->run();

    // Assert
    foreach (['34112', '27520', '32204'] as $ratingKey) {
        expect(showByRatingKey($ratingKey)->episodes_synced_at)->not->toBeNull();
    }
});

it('leaves the episode watermark unstamped for a show whose crawl failed', function (): void {
    // Arrange
    fakePlexSeedCrawl(failLeavesForRatingKey: '34112');

    // Act
    $this->artisan('plex:sync')->run();

    // Assert
    expect(showByRatingKey('34112')->episodes_synced_at)->toBeNull();
    expect(showByRatingKey('27520')->episodes_synced_at)->not->toBeNull();
});

// The second fakePlexSeedCrawl() in Arrange also resets the recorded requests,
// so the assertions below see the SECOND run only. Its payload is byte-identical
// to the first run's, so show 34112 is NOT in the changed set — only its
// unstamped episode watermark can put it back in the crawl. The 24 episodes of
// the shared allLeaves fixture are re-parented to whichever show was crawled
// last, so a row under 34112 proves that show was the one crawled.
it('re-crawls a show whose episode crawl failed even though nothing changed', function (): void {
    // Arrange
    fakePlexSeedCrawl(failLeavesForRatingKey: '34112');
    $this->artisan('plex:sync')->run();
    freshPlexHttpFactory();
    fakePlexSeedCrawl();

    // Act
    $this->artisan('plex:sync')->run();

    // Assert
    Http::assertSent(fn ($request): bool => Str::contains((string) $request->url(), '/library/metadata/34112/allLeaves'));
    expect(PlexEpisode::query()->where('plex_show_id', showByRatingKey('34112')->id)->count())->toBeGreaterThan(0);
});

/**
 * Drop the faked HTTP factory so a second fakePlexSeedCrawl() in one test starts
 * clean. Http::fake() MERGES its stubs into the existing ones (the earliest
 * match wins) and never rewinds a spent Http::sequence(), so without this the
 * second run would keep hitting the first run's exhausted section pagers and its
 * throwing allLeaves stub. Re-arms the globally-armed preventStrayRequests() on
 * the replacement factory.
 */
function freshPlexHttpFactory(): void
{
    app()->forgetInstance(Factory::class);
    Http::clearResolvedInstance(Factory::class);
    Http::preventStrayRequests();
}

/**
 * The single show carrying the given Plex ratingKey — the crawl imports one row
 * per ratingKey, so sole() also guards against a duplicate slipping in.
 */
function showByRatingKey(string $ratingKey): PlexShow
{
    return PlexShow::query()->where('_plex_ratingKey', $ratingKey)->sole();
}

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

    // episodes_synced_at is pinned to the show's own _plex_updatedAt (not now())
    // so "unchanged" means unchanged at both levels — a caught-up episode crawl
    // that the stale-show arm has no reason to pick back up, whatever the clock
    // reads relative to the fixture's epochs.
    PlexShow::factory()->create([
        'plex_server_id' => $library->plex_server_id,
        'plex_library_id' => $library->id,
        '_plex_ratingKey' => $ratingKey,
        '_plex_leafCount' => $leafCount,
        '_plex_updatedAt' => Date::createFromTimestamp($updatedAtEpoch),
        'episodes_synced_at' => Date::createFromTimestamp($updatedAtEpoch),
    ]);
}
