<?php

declare(strict_types=1);

use App\Domains\Common\Exceptions\PlexRequestFailed;
use App\Domains\PlexLibrary\Models\PlexEpisode;
use App\Domains\PlexLibrary\Models\PlexLibrary;
use App\Domains\PlexLibrary\Models\PlexMovie;
use App\Domains\PlexLibrary\Models\PlexSeason;
use App\Domains\PlexLibrary\Models\PlexServer;
use App\Domains\PlexLibrary\Models\PlexShow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Symfony\Component\Console\Command\Command;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| plex:seed — full-depth crawl command slice
|--------------------------------------------------------------------------
| The command discovers the configured server, walks both library sections,
| and reconciles the server, libraries, movies, shows, and per-show episodes
| into the database. Every outbound Plex call is faked at the wire by
| host-agnostic path pattern (Http::preventStrayRequests is global). This
| slice asserts the observable end-state only — row counts and the crawl's
| per-show allLeaves requests — never per-row identity, which the reconcilers
| own in their own tests. The shared fake lives in fakePlexSeedCrawl().
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
|     capture of 3 shows (ratingKeys 34112, 27520, 32204); totalSize 35, likewise
|     terminated with an empty trailing page. Copied byte-exact from
|     .context/plex-captures/section_show_all_includeGuids.json.
|   tests/Fixtures/PlexLibrary/plex/show_children_seasons.json — one season
|     Metadata row; reused for every show's /children fetch.
|   tests/Fixtures/PlexLibrary/plex/show_allLeaves_episodes.json — 24 episode
|     Metadata rows (show 34112); reused for every show's /allLeaves fetch.
|
| The per-show failure tests pass a ratingKey to fakePlexSeedCrawl() to wire
| that show's /allLeaves fetch to throw a ConnectionException — a synthesized
| transport failure real data can't produce, the only fault that actually throws
| here (a 5xx is retried then read as empty leaves, never thrown).
*/

it('upserts the plex server row', function (): void {
    // Arrange
    fakePlexSeedCrawl();

    // Act
    $this->artisan('plex:seed')->run();

    // Assert
    expect(PlexServer::query()->count())->toBe(1);
});

it('imports the two library rows', function (): void {
    // Arrange
    fakePlexSeedCrawl();

    // Act
    $this->artisan('plex:seed')->run();

    // Assert
    expect(PlexLibrary::query()->count())->toBe(2);
});

it('imports the three movie rows', function (): void {
    // Arrange
    fakePlexSeedCrawl();

    // Act
    $this->artisan('plex:seed')->run();

    // Assert
    expect(PlexMovie::query()->count())->toBe(3);
});

it('imports the three show rows', function (): void {
    // Arrange
    fakePlexSeedCrawl();

    // Act
    $this->artisan('plex:seed')->run();

    // Assert
    expect(PlexShow::query()->count())->toBe(3);
});

it('crawls the episode leaves of every show', function (): void {
    // Arrange
    fakePlexSeedCrawl();

    // Act
    $this->artisan('plex:seed')->run();

    // Assert
    foreach (['34112', '27520', '32204'] as $ratingKey) {
        Http::assertSent(fn ($request): bool => Str::contains((string) $request->url(), "/library/metadata/{$ratingKey}/allLeaves"));
    }
    expect(PlexEpisode::query()->count())->toBeGreaterThan(0);
});

it('imports the healthy shows despite one show failing', function (): void {
    // Arrange
    fakePlexSeedCrawl(failLeavesForRatingKey: '34112');

    // Act
    $this->artisan('plex:seed')->run();

    // Assert
    expect(PlexEpisode::query()->count())->toBeGreaterThan(0);
});

it('reports the failing show fetch instead of swallowing it', function (): void {
    // Arrange
    Exceptions::fake();
    fakePlexSeedCrawl(failLeavesForRatingKey: '34112');

    // Act
    $this->artisan('plex:seed')->run();

    // Assert
    Exceptions::assertReported(PlexRequestFailed::class);
});

it('exits FAILURE when a show failed', function (): void {
    // Arrange
    fakePlexSeedCrawl(failLeavesForRatingKey: '34112');

    // Act & Assert
    $this->artisan('plex:seed')->assertExitCode(Command::FAILURE);
});

it('emits heartbeat output for each phase', function (): void {
    // Arrange
    fakePlexSeedCrawl();

    // Act & Assert
    $this->artisan('plex:seed')
        ->expectsOutputToContain('Connecting to Plex server')
        ->expectsOutputToContain('[libraries 2]')
        ->expectsOutputToContain('[movies 3]')
        ->expectsOutputToContain('[shows 3]')
        ->expectsOutputToContain('[episodes 72]')
        ->expectsOutputToContain('Done.')
        ->run();
});

// The channel IS configured, so the silence is the seed's own policy rather
// than an unroutable notification: a first-time seed imports the entire library
// and would otherwise announce every row in it.
it('announces nothing even though it imports the whole library', function (): void {
    // Arrange
    Notification::fake();
    config()->set('services.slack.notifications.channel', '#lundflix');
    fakePlexSeedCrawl();

    // Act
    $this->artisan('plex:seed')->run();

    // Assert
    Notification::assertNothingSent();
});

// The row counts are what keep this honest: "no pending rows" is vacuously true
// on an empty table, so the seed has to be proven to have inserted the rows it
// then declared already-announced.
it('leaves nothing pending for a later run to announce', function (): void {
    // Arrange
    fakePlexSeedCrawl();

    // Act
    $this->artisan('plex:seed')->run();

    // Assert
    expect(PlexMovie::query()->count())->toBe(3);
    expect(PlexEpisode::query()->count())->toBeGreaterThan(0);
    expect(PlexMovie::query()->whereNull('announced_at')->count())->toBe(0);
    expect(PlexEpisode::query()->whereNull('announced_at')->count())->toBe(0);
});

// The seed speaks only for the server it just crawled, so a sibling server's
// backlog is somebody else's news — stamping it would silently drop rows
// SelectRipeAnnouncements can never reach again (it only ever selects nulls).
it('leaves another server pending', function (): void {
    // Arrange
    $other = PlexServer::factory()->create();
    $library = PlexLibrary::factory()->create(['plex_server_id' => $other->id]);
    $show = PlexShow::factory()->create(['plex_server_id' => $other->id, 'plex_library_id' => $library->id]);
    $season = PlexSeason::factory()->create(['plex_show_id' => $show->id]);
    $movie = PlexMovie::factory()->create([
        'plex_server_id' => $other->id,
        'plex_library_id' => $library->id,
        'announced_at' => null,
    ]);
    $episode = PlexEpisode::factory()->create(['plex_season_id' => $season->id, 'announced_at' => null]);
    fakePlexSeedCrawl();

    // Act
    $this->artisan('plex:seed')->run();

    // Assert
    expect($movie->refresh()->announced_at)->toBeNull();
    expect($episode->refresh()->announced_at)->toBeNull();
});

// The row is one plex:sync inserted moments before the operator ran the seed,
// still legitimately waiting out its debounce window. The seed only owns what
// it inserted itself, so an arrival that predates the run stays pending —
// arranged by writing created_at directly rather than travelling the clock.
it('leaves a row that predates the run pending', function (): void {
    // Arrange
    $server = PlexServer::factory()->create(['_plex_clientIdentifier' => 'servermachineidentifier000000000']);
    $library = PlexLibrary::factory()->create([
        'plex_server_id' => $server->id,
        '_plex_key' => '1',
        '_plex_type' => 'movie',
    ]);
    $movie = PlexMovie::factory()->create([
        'plex_server_id' => $server->id,
        'plex_library_id' => $library->id,
        '_plex_ratingKey' => '26278',
        'announced_at' => null,
        'created_at' => now()->subMinute(),
    ]);
    fakePlexSeedCrawl();

    // Act
    $this->artisan('plex:seed')->run();

    // Assert
    expect($movie->refresh()->announced_at)->toBeNull();
});

it('emits an episode-count heartbeat every 100 episodes', function (): void {
    // Arrange
    fakePlexSeedCrawl(showSection: 'PlexLibrary/plex/section_show_all_twelve_includeGuids.json');

    // Act & Assert
    $this->artisan('plex:seed')
        ->doesntExpectOutputToContain('[episodes 120]')
        ->doesntExpectOutputToContain('[episodes 216]')
        ->expectsOutputToContain('[episodes 100]')
        ->expectsOutputToContain('[episodes 200]')
        ->expectsOutputToContain('[episodes 288]')
        ->run();
});
