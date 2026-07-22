<?php

declare(strict_types=1);

use App\Domains\Catalog\Actions\SeedTvdbEpisodes;
use App\Domains\Catalog\Models\Episode;
use App\Domains\Catalog\Models\Season;
use App\Domains\Catalog\Models\Show;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Fixtures (byte-exact real TheTVDB v4 slices) — on-demand episode seeding
|--------------------------------------------------------------------------
| SeedTvdbEpisodes walks TvdbApiService::episodes($show->_tvdb_id) (default
| season type), upserts via UpsertTvdbEpisodes, resolves each episode's
| season_id to the show's default-type season, and stamps episodes_synced_at.
|
| tests/Fixtures/Catalog/tvdb/login.json — POST /login → data.token JWT;
|   every fake map answers it because Http::preventStrayRequests() is global
|   and the JWT is fetched (and cached) before any /series call.
| series_episodes_page1.json — 3 episodes (ids 4350173/4/5), all seasonNumber 0,
|   links.next → page 2.
| series_episodes_page2.json — 3 episodes (ids 420649/8/50), all seasonNumber 20,
|   links.next null. Total across both pages: 6 episodes, seriesId 71663.
|
| Single-walk tests drive the two pages with Http::sequence() (like
| TvdbEpisodesTest). The idempotent test needs TWO full walks, so it branches
| per-URL (page=1 → page2, else page1) so a second walk re-serves both pages.
*/

beforeEach(function (): void {
    Cache::flush();
    config(['services.tvdb.key' => 'test-key']);
});

it('walks both pages and persists every episode', function (): void {
    // Arrange
    $show = Show::factory()->create(['_tvdb_id' => 71663, '_tvdb_defaultSeasonType' => 1]);
    Http::fake([
        '*api4.thetvdb.com/v4/login*' => Http::response(fixtureBytes('Catalog/tvdb/login.json')),
        '*api4.thetvdb.com/v4/series/*' => Http::sequence()
            ->push(fixtureBytes('Catalog/tvdb/series_episodes_page1.json'), 200)
            ->push(fixtureBytes('Catalog/tvdb/series_episodes_page2.json'), 200),
    ]);

    // Act
    resolve(SeedTvdbEpisodes::class)->handle($show);

    // Assert
    expect($show->episodes()->count())->toBe(6);
    $this->assertDatabaseCount('episodes', 6);
});

it('resolves season_id to the default-type season matching seasonNumber', function (): void {
    // Arrange
    $show = Show::factory()->create(['_tvdb_id' => 71663, '_tvdb_defaultSeasonType' => 1]);
    $season = Season::factory()->create([
        'show_id' => $show->id,
        '_tvdb_number' => 0,
        '_tvdb_type' => ['id' => 1, 'name' => 'Aired Order', 'type' => 'official'],
    ]);
    Http::fake([
        '*api4.thetvdb.com/v4/login*' => Http::response(fixtureBytes('Catalog/tvdb/login.json')),
        '*api4.thetvdb.com/v4/series/*' => Http::sequence()
            ->push(fixtureBytes('Catalog/tvdb/series_episodes_page1.json'), 200)
            ->push(fixtureBytes('Catalog/tvdb/series_episodes_page2.json'), 200),
    ]);

    // Act
    resolve(SeedTvdbEpisodes::class)->handle($show);

    // Assert
    expect(Episode::where('_tvdb_seasonNumber', 0)->pluck('season_id')->unique()->all())->toBe([$season->id]);
});

it('leaves season_id null when no matching default-type season exists', function (): void {
    // Arrange
    $show = Show::factory()->create(['_tvdb_id' => 71663, '_tvdb_defaultSeasonType' => 1]);
    Http::fake([
        '*api4.thetvdb.com/v4/login*' => Http::response(fixtureBytes('Catalog/tvdb/login.json')),
        '*api4.thetvdb.com/v4/series/*' => Http::sequence()
            ->push(fixtureBytes('Catalog/tvdb/series_episodes_page1.json'), 200)
            ->push(fixtureBytes('Catalog/tvdb/series_episodes_page2.json'), 200),
    ]);

    // Act
    resolve(SeedTvdbEpisodes::class)->handle($show);

    // Assert
    expect(Episode::where('_tvdb_seasonNumber', 20)->pluck('season_id')->unique()->all())->toBe([null]);
});

it('leaves season_id null when the only seasonNumber match is a non-default-type season', function (): void {
    // Arrange
    $show = Show::factory()->create(['_tvdb_id' => 71663, '_tvdb_defaultSeasonType' => 1]);
    Season::factory()->create([
        'show_id' => $show->id,
        '_tvdb_number' => 0,
        '_tvdb_type' => ['id' => 2, 'name' => 'DVD Order', 'type' => 'dvd'],
    ]);
    Http::fake([
        '*api4.thetvdb.com/v4/login*' => Http::response(fixtureBytes('Catalog/tvdb/login.json')),
        '*api4.thetvdb.com/v4/series/*' => Http::sequence()
            ->push(fixtureBytes('Catalog/tvdb/series_episodes_page1.json'), 200)
            ->push(fixtureBytes('Catalog/tvdb/series_episodes_page2.json'), 200),
    ]);

    // Act
    resolve(SeedTvdbEpisodes::class)->handle($show);

    // Assert
    expect(Episode::where('_tvdb_seasonNumber', 0)->pluck('season_id')->unique()->all())->toBe([null]);
});

it('stamps the show\'s episodes_synced_at', function (): void {
    // Arrange
    $show = Show::factory()->create(['_tvdb_id' => 71663, '_tvdb_defaultSeasonType' => 1]);
    Http::fake([
        '*api4.thetvdb.com/v4/login*' => Http::response(fixtureBytes('Catalog/tvdb/login.json')),
        '*api4.thetvdb.com/v4/series/*' => Http::sequence()
            ->push(fixtureBytes('Catalog/tvdb/series_episodes_page1.json'), 200)
            ->push(fixtureBytes('Catalog/tvdb/series_episodes_page2.json'), 200),
    ]);

    // Act
    resolve(SeedTvdbEpisodes::class)->handle($show);

    // Assert
    expect($show->fresh()->episodes_synced_at)->not->toBeNull();
});

it('completes when a default-type season has a null _tvdb_number', function (): void {
    // Arrange
    $show = Show::factory()->create(['_tvdb_id' => 71663, '_tvdb_defaultSeasonType' => 1]);
    Season::factory()->create([
        'show_id' => $show->id,
        '_tvdb_number' => null,
        '_tvdb_type' => ['id' => 1, 'name' => 'Aired Order', 'type' => 'official'],
    ]);
    Season::factory()->create([
        'show_id' => $show->id,
        '_tvdb_number' => 0,
        '_tvdb_type' => ['id' => 1, 'name' => 'Aired Order', 'type' => 'official'],
    ]);
    Http::fake([
        '*api4.thetvdb.com/v4/login*' => Http::response(fixtureBytes('Catalog/tvdb/login.json')),
        '*api4.thetvdb.com/v4/series/*' => Http::sequence()
            ->push(fixtureBytes('Catalog/tvdb/series_episodes_page1.json'), 200)
            ->push(fixtureBytes('Catalog/tvdb/series_episodes_page2.json'), 200),
    ]);

    // Act
    resolve(SeedTvdbEpisodes::class)->handle($show);

    // Assert
    expect($show->fresh()->episodes_synced_at)->not->toBeNull();
});

it('re-seeds idempotently — clears season_id when the default-type season no longer matches', function (): void {
    // Arrange
    $show = Show::factory()->create(['_tvdb_id' => 71663, '_tvdb_defaultSeasonType' => 1]);
    Season::factory()->create([
        'show_id' => $show->id,
        '_tvdb_number' => 0,
        '_tvdb_type' => ['id' => 1, 'name' => 'Aired Order', 'type' => 'official'],
    ]);
    Http::fake([
        '*api4.thetvdb.com/v4/login*' => Http::response(fixtureBytes('Catalog/tvdb/login.json')),
        '*api4.thetvdb.com/v4/series/*' => fn (Request $request) => Str::contains($request->url(), 'page=1')
            ? Http::response(fixtureBytes('Catalog/tvdb/series_episodes_page2.json'))
            : Http::response(fixtureBytes('Catalog/tvdb/series_episodes_page1.json')),
    ]);
    resolve(SeedTvdbEpisodes::class)->handle($show);
    $show->update(['_tvdb_defaultSeasonType' => 2]);

    // Act
    resolve(SeedTvdbEpisodes::class)->handle($show);

    // Assert
    expect(Episode::where('_tvdb_seasonNumber', 0)->pluck('season_id')->unique()->all())->toBe([null]);
});

it('preserves existing season_id links when the default season type is null', function (): void {
    // Arrange
    $show = Show::factory()->create(['_tvdb_id' => 71663, '_tvdb_defaultSeasonType' => null]);
    $season = Season::factory()->create([
        'show_id' => $show->id,
        '_tvdb_number' => 0,
        '_tvdb_type' => ['id' => 1, 'name' => 'Aired Order', 'type' => 'official'],
    ]);
    $episode = Episode::factory()->create([
        'show_id' => $show->id,
        'season_id' => $season->id,
        '_tvdb_id' => 555001,
        '_tvdb_seasonNumber' => 0,
    ]);
    Http::fake([
        '*api4.thetvdb.com/v4/login*' => Http::response(fixtureBytes('Catalog/tvdb/login.json')),
        '*api4.thetvdb.com/v4/series/*' => Http::sequence()
            ->push(fixtureBytes('Catalog/tvdb/series_episodes_page1.json'), 200)
            ->push(fixtureBytes('Catalog/tvdb/series_episodes_page2.json'), 200),
    ]);

    // Act
    resolve(SeedTvdbEpisodes::class)->handle($show);

    // Assert
    expect($episode->fresh()->season_id)->toBe($season->id)
        ->and($show->fresh()->episodes_synced_at)->not->toBeNull();
});

it('clears a stale season_id when the episode seasonNumber is now null', function (): void {
    // Arrange
    $show = Show::factory()->create(['_tvdb_id' => 71663, '_tvdb_defaultSeasonType' => 1]);
    $season = Season::factory()->create([
        'show_id' => $show->id,
        '_tvdb_number' => 0,
        '_tvdb_type' => ['id' => 1, 'name' => 'Aired Order', 'type' => 'official'],
    ]);
    $episode = Episode::factory()->create([
        'show_id' => $show->id,
        'season_id' => $season->id,
        '_tvdb_id' => 555001,
        '_tvdb_seasonNumber' => null,
    ]);
    Http::fake([
        '*api4.thetvdb.com/v4/login*' => Http::response(fixtureBytes('Catalog/tvdb/login.json')),
        '*api4.thetvdb.com/v4/series/*' => Http::sequence()
            ->push(fixtureBytes('Catalog/tvdb/series_episodes_page1.json'), 200)
            ->push(fixtureBytes('Catalog/tvdb/series_episodes_page2.json'), 200),
    ]);

    // Act
    resolve(SeedTvdbEpisodes::class)->handle($show);

    // Assert
    expect($episode->fresh()->season_id)->toBeNull();
});

it('is idempotent — a second seed adds no duplicate episodes', function (): void {
    // Arrange
    $show = Show::factory()->create(['_tvdb_id' => 71663, '_tvdb_defaultSeasonType' => 1]);
    Http::fake([
        '*api4.thetvdb.com/v4/login*' => Http::response(fixtureBytes('Catalog/tvdb/login.json')),
        '*api4.thetvdb.com/v4/series/*' => fn (Request $request) => Str::contains($request->url(), 'page=1')
            ? Http::response(fixtureBytes('Catalog/tvdb/series_episodes_page2.json'))
            : Http::response(fixtureBytes('Catalog/tvdb/series_episodes_page1.json')),
    ]);

    // Act
    resolve(SeedTvdbEpisodes::class)->handle($show);
    resolve(SeedTvdbEpisodes::class)->handle($show);

    // Assert
    $this->assertDatabaseCount('episodes', 6);
});
