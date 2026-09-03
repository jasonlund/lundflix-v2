<?php

declare(strict_types=1);

use App\Domains\PlexLibrary\Actions\ReconcilePlexShows;
use App\Domains\PlexLibrary\Models\PlexEpisode;
use App\Domains\PlexLibrary\Models\PlexLibrary;
use App\Domains\PlexLibrary\Models\PlexMovie;
use App\Domains\PlexLibrary\Models\PlexServer;
use App\Domains\PlexLibrary\Models\PlexShow;
use App\Domains\PlexLibrary\Notifications\RecentlyAddedToPlex;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Symfony\Component\Console\Command\Command;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| plex:sync — incremental crawl command slice
|--------------------------------------------------------------------------
| The command reconciles the top level (server, libraries, movies, shows) on
| EVERY run, but crawls episodes ONLY for the shows whose episode watermark is
| behind: the app-owned episodes_synced_at column IS the crawl set. Nothing is
| accumulated across a run and no changed set is returned — ReconcilePlexShows
| nulls the watermark of a show whose stored _plex_updatedAt / _plex_leafCount
| moved as it writes the page, so the selection is read back off the table.
| Every outbound Plex call is faked at the wire by host-agnostic path pattern
| (Http::preventStrayRequests is global). This slice asserts the observable
| end-state and the per-show crawl requests only — never per-row identity, which
| the reconcilers own in their own tests. The shared fake lives in
| fakePlexSeedCrawl().
|
| That marking is the ONLY thing that can put two kinds of show back in the
| crawl, because ReconcilePlexShows writes the incoming
| _plex_updatedAt/_plex_leafCount before the episodes are fetched, leaving no
| diff for any later read to find: a show whose /allLeaves fetch threw (its
| watermark stayed null / behind), and a show whose leafCount moved while its
| updatedAt held still (nothing an updatedAt comparison can see at all).
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
|
| The announcement is no longer this command's own output. A sync run leaves what
| it inserted PENDING and announces nothing: the rows it just wrote are seconds
| old, so they sit inside the debounce windows until a later run finds them quiet.
| What ships, and when, belongs to SelectRipeAnnouncements / NotifyRecentlyAdded
| and is asserted in their own tests — here the crawl only has to stay silent
| while still proving it really inserted the rows it stayed silent about.
*/

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
 * Pre-seed the server and its show library, resolved by their natural keys
 * (firstOrCreate) so repeated calls in one test share one ancestor chain while
 * each test starts from a rolled-back DB. The run reuses this chain — both
 * UpsertPlexServer and ReconcilePlexLibraries updateOrCreate on those same
 * natural keys — which keeps ids stable across the pre-seed and the crawl.
 */
function seedShowLibrary(): PlexLibrary
{
    $server = PlexServer::query()->firstOrCreate(
        ['_plex_clientIdentifier' => 'servermachineidentifier000000000'],
        PlexServer::factory()->raw(['_plex_clientIdentifier' => 'servermachineidentifier000000000']),
    );

    return PlexLibrary::query()->firstOrCreate(
        ['plex_server_id' => $server->id, '_plex_key' => '2'],
        PlexLibrary::factory()->raw(['plex_server_id' => $server->id, '_plex_key' => '2', '_plex_type' => 'show']),
    );
}

/**
 * Pre-seed one show through the PRODUCTION write path — ReconcilePlexShows on
 * the very fixture item the crawl will serve, with only leafCount replaced — so
 * the stored _plex_updatedAt is whatever that path renders and the run's own
 * write of it provably cannot differ. Only the leaf count is out of date.
 *
 * The seeding upsert marks the fresh row by nulling its watermark, so the stamp
 * is applied afterwards and pinned to the show's own stored _plex_updatedAt: a
 * caught-up crawl that neither the null nor the behind-updatedAt arm has any
 * reason to pick up. Never a hand-written datetime literal — this suite runs on
 * sqlite and production on MySQL, so the driver has to render both sides.
 */
function seedShowWithStaleLeafCount(string $ratingKey, int $storedLeafCount): void
{
    $library = seedShowLibrary();

    $item = collect(json_decode(fixtureBytes('PlexLibrary/plex/section_show_all_includeGuids.json'), true)['MediaContainer']['Metadata'])
        ->firstWhere('ratingKey', $ratingKey);

    resolve(ReconcilePlexShows::class)->upsertPage(
        PlexServer::query()->findOrFail($library->plex_server_id),
        $library,
        [[...$item, 'leafCount' => $storedLeafCount]],
        now(),
    );

    $show = showByRatingKey($ratingKey);
    $show->update(['episodes_synced_at' => $show->_plex_updatedAt]);
}

/**
 * Pre-seed the server, its show library, and one PlexShow whose stored
 * _plex_updatedAt / _plex_leafCount match the fixture exactly, so
 * ReconcilePlexShows sees the show as unchanged.
 */
function seedUnchangedShow(string $ratingKey, int $leafCount, int $updatedAtEpoch): void
{
    $library = seedShowLibrary();

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

describe('plex:sync crawl selection', function (): void {
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

    // The one case no read of _plex_updatedAt can ever see: the stored row carries
    // the fixture's own updatedAt (written through ReconcilePlexShows itself, so it
    // is byte-identical to what this run writes) and a caught-up episode watermark
    // pinned to it, so both stale-watermark arms are dead. Only leafCount moved, and
    // only the marking upsertPage does can turn that into a crawl.
    it('crawls a show whose only change is its leaf count', function (): void {
        // Arrange
        fakePlexSeedCrawl();
        seedShowWithStaleLeafCount('27520', 3);

        // Act
        $this->artisan('plex:sync')->run();

        // Assert
        Http::assertSent(fn ($request): bool => Str::contains((string) $request->url(), '/library/metadata/27520/children'));
        Http::assertSent(fn ($request): bool => Str::contains((string) $request->url(), '/library/metadata/27520/allLeaves'));
    });
});

// plex:sync inherits its heartbeat from the same base command plex:seed uses, so
// this is the only thing holding the subclass to the shared source-tagged shape —
// without it the base could be retagged and this command silently left behind.
describe('plex:sync heartbeat output', function (): void {
    it('emits source-tagged heartbeat output for each phase', function (): void {
        // Arrange
        fakePlexSeedCrawl();

        // Act & Assert
        $this->artisan('plex:sync')
            ->expectsOutputToContain('Connecting to Plex server')
            ->expectsOutputToContain('[plex libraries 2]')
            ->expectsOutputToContain('[plex movies 3]')
            ->expectsOutputToContain('[plex shows 3]')
            ->expectsOutputToContain('[plex episodes 72]')
            ->expectsOutputToContain('Done.')
            ->run();
    });
});

describe('plex:sync episode watermark & failure', function (): void {
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
    // to the first run's, so show 34112 does NOT read as moved and is never marked —
    // only its unstamped episode watermark can put it back in the crawl. The 24 episodes of
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
});

describe('plex:sync announcements', function (): void {
    // The pending count is what keeps this honest: silence alone would also pass on a
    // run that inserted nothing at all.
    it('announces nothing for the arrivals it just added, which are still inside the debounce window', function (): void {
        // Arrange
        Notification::fake();
        config()->set('services.slack.notifications.channel', '#lundflix');
        fakePlexSeedCrawl();

        // Act
        $this->artisan('plex:sync')->run();

        // Assert
        Notification::assertNothingSent();
        expect(PlexMovie::query()->whereNull('announced_at')->count())->toBe(3);
        expect(PlexEpisode::query()->whereNull('announced_at')->count())->toBeGreaterThan(0);
    });

    // The channel is configured only for the SECOND run: the queue connection is
    // sync under test, so a first run that announced would deliver to Slack for
    // real instead of being counted by a fake that doesn't exist yet. Faking after
    // it therefore proves the silence belongs to the re-run, which reconciles the
    // identical payload and inserts nothing.
    it('announces nothing on a re-run that added nothing', function (): void {
        // Arrange
        fakePlexSeedCrawl();
        $this->artisan('plex:sync')->run();
        freshPlexHttpFactory();
        fakePlexSeedCrawl();
        Notification::fake();
        config()->set('services.slack.notifications.channel', '#lundflix');

        // Act
        $this->artisan('plex:sync')->run();

        // Assert
        Notification::assertNothingSent();
    });

    // The window is passed by ageing the pending rows' created_at directly rather than
    // travelling the clock: the second crawl re-upserts the very same rows, and
    // created_at is insert-only, so the ageing survives the re-run. 1000s clears both
    // quiet windows and the 900s hard deadline, so every pending bucket is ripe.
    //
    // The channel is configured only for the SECOND run, after Notification::fake(),
    // for the same reason as the silent re-run test above: the queue connection is sync
    // under test, so a first run that announced would deliver to Slack for real.
    it('announces the pending arrivals in one message once the window has passed', function (): void {
        // Arrange
        fakePlexSeedCrawl();
        $this->artisan('plex:sync')->run();
        PlexMovie::query()->update(['created_at' => now()->subSeconds(1000)]);
        PlexEpisode::query()->update(['created_at' => now()->subSeconds(1000)]);
        freshPlexHttpFactory();
        fakePlexSeedCrawl();
        Notification::fake();
        config()->set('services.slack.notifications.channel', '#lundflix');

        // Act
        $this->artisan('plex:sync')->run();

        // Assert
        Notification::assertSentOnDemandTimes(RecentlyAddedToPlex::class, 1);
    });
});
