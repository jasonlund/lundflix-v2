<?php

declare(strict_types=1);

use App\Domains\Common\Exceptions\PlexRequestFailed;
use App\Domains\PlexLibrary\Models\PlexEpisode;
use App\Domains\PlexLibrary\Models\PlexLibrary;
use App\Domains\PlexLibrary\Models\PlexMovie;
use App\Domains\PlexLibrary\Models\PlexServer;
use App\Domains\PlexLibrary\Models\PlexShow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
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
| slice asserts the observable end-state only — row counts, the crawl's per-show
| allLeaves requests, and the ownership + episode watermark those requests leave
| behind — never per-row identity, which the reconcilers own in their own tests.
| The shared fake lives in fakePlexSeedCrawl().
|
| The last four tests cover the page loop of a genuinely multi-page library: the
| pages accumulate rather than the earlier ones being dropped, every row of every
| page carries the one synced_at taken per library (not per page), a page fetch
| that fails mid-walk leaves the pages already read AND the stale rows alone, and
| only a completed walk sweeps a vanished row. The stamp-sensitive tests arrange
| their pre-existing row through staleMovie() (whose stamp rule that helper
| documents) or freeze the clock.
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
|   tests/Fixtures/PlexLibrary/plex/section_all_page1.json + section_all_page2.json
|     — real captures of the SAME movie section paged: page 1 offset 0, size 2
|     (ratingKeys 26278, 36080), page 2 offset 2, size 1 (ratingKey 32202), both
|     totalSize 3. The walk terminates on totalSize, so this pair needs no empty
|     trailing page and is the only multi-page section in the suite.
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

// Both halves of a completed crawl, per show: the leaves were fetched, the
// watermark was stamped, and the rows landed owned by a real show, season, and
// server. preventAccessingMissingAttributes is not enabled anywhere in this app,
// so a column the crawl's show selection stops loading reads back as null rather
// than throwing — a too-narrow selection can only surface as zero episodes, an
// unowned row, or an unstamped watermark, never as a type error. The three
// columns under that net are the ones the consumers touch: id and
// plex_server_id (ReconcilePlexEpisodes' owner + match keys), and
// _plex_ratingKey (the fetch URL). The shared allLeaves fixture serves the same
// 24 ratingKeys to every show, so the episode rows are re-parented to whichever
// show was crawled last — hence the ownership assertion is over the crawled set,
// not per show.
it('crawls the episode leaves of every show and lands them under a crawled show', function (): void {
    // Arrange
    fakePlexSeedCrawl();

    // Act
    $this->artisan('plex:seed')->run();

    // Assert
    foreach (['34112', '27520', '32204'] as $ratingKey) {
        Http::assertSent(fn ($request): bool => Str::contains((string) $request->url(), "/library/metadata/{$ratingKey}/allLeaves"));
        expect(PlexShow::query()->where('_plex_ratingKey', $ratingKey)->sole()->episodes_synced_at)->not->toBeNull();
    }
    expect(PlexEpisode::query()->count())->toBe(24);
    expect(PlexEpisode::query()
        ->whereIn('plex_show_id', PlexShow::query()->pluck('id'))
        ->whereIn('plex_server_id', PlexServer::query()->pluck('id'))
        ->whereNotNull('plex_season_id')
        ->count())->toBe(24);
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

it('imports every row of every page of a multi-page movie library', function (): void {
    // Arrange
    fakePlexSeedCrawl(movieSectionPages: [
        'PlexLibrary/plex/section_all_page1.json',
        'PlexLibrary/plex/section_all_page2.json',
    ]);

    // Act
    $this->artisan('plex:seed')->run();

    // Assert
    expect(PlexMovie::query()->pluck('_plex_ratingKey')->all())
        ->toEqualCanonicalizing(['26278', '36080', '32202']);
});

it('stamps every page of one movie library with a single synced_at', function (): void {
    // Arrange
    $this->freezeTime();
    fakePlexSeedCrawl(movieSectionPages: [
        'PlexLibrary/plex/section_all_page1.json',
        function () {
            // The clock moves only at the page boundary, so a $now taken per page
            // instead of per library lands page 2 on a second distinct stamp — and
            // the sweep that follows would then delete page 1.
            $this->travel(1)->second();

            return Http::response(fixtureBytes('PlexLibrary/plex/section_all_page2.json'));
        },
    ]);

    // Act
    $this->artisan('plex:seed')->run();

    // Assert
    expect(PlexMovie::query()->count())->toBe(3);
    expect(PlexMovie::query()->pluck('synced_at')->unique()->all())->toHaveCount(1);
});

it('keeps the pages already read and prunes nothing when a page fetch fails mid-walk', function (): void {
    // Arrange
    $server = PlexServer::factory()->create(['_plex_clientIdentifier' => 'servermachineidentifier000000000']);
    $library = PlexLibrary::factory()->create(['plex_server_id' => $server->id, '_plex_key' => '1', '_plex_type' => 'movie']);
    staleMovie($server, $library, '11111');
    fakePlexSeedCrawl(movieSectionPages: ['PlexLibrary/plex/section_all_page1.json'], failMoviePage: 2);

    // Act & Assert
    expect(fn () => $this->artisan('plex:seed')->run())->toThrow(PlexRequestFailed::class);
    expect(PlexMovie::query()->pluck('_plex_ratingKey')->all())
        ->toEqualCanonicalizing(['26278', '36080', '11111']);
});

it('prunes a movie that vanished from the section once the walk completes', function (): void {
    // Arrange
    $server = PlexServer::factory()->create(['_plex_clientIdentifier' => 'servermachineidentifier000000000']);
    $library = PlexLibrary::factory()->create(['plex_server_id' => $server->id, '_plex_key' => '1', '_plex_type' => 'movie']);
    staleMovie($server, $library, '11111');
    fakePlexSeedCrawl(movieSectionPages: [
        'PlexLibrary/plex/section_all_page1.json',
        'PlexLibrary/plex/section_all_page2.json',
    ]);

    // Act
    $this->artisan('plex:seed')->run();

    // Assert
    expect(PlexMovie::query()->pluck('_plex_ratingKey')->all())
        ->toEqualCanonicalizing(['26278', '36080', '32202']);
});
