<?php

declare(strict_types=1);

use App\Domains\PlexLibrary\Actions\ReconcilePlexShows;
use App\Domains\PlexLibrary\Models\PlexEpisode;
use App\Domains\PlexLibrary\Models\PlexLibrary;
use App\Domains\PlexLibrary\Models\PlexSeason;
use App\Domains\PlexLibrary\Models\PlexServer;
use App\Domains\PlexLibrary\Models\PlexShow;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Date;

/*
|--------------------------------------------------------------------------
| The show reconciler is page-at-a-time mark-and-sweep: `upsertPage()` writes
| one page of Metadata stamped with the caller's `$now` and NEVER deletes, then
| a single `prune()` sweeps whatever this server+library still carries an older
| stamp than that same `$now`. `$now` is the injected clock seam — the action
| never reads the clock itself, so every case asserts the exact stamp handed in.
|
| Every prune case freezes the clock and arranges its rows through `staleShow()`,
| whose stamp rule that helper documents.
|--------------------------------------------------------------------------
*/

/**
 * A verbatim transcription of the ratingKey 34112 Metadata item in the committed
 * fixture tests/Fixtures/PlexLibrary/plex/section_show_all_includeGuids.json — diff
 * the two to confirm the mapped facts and Guid[] crosswalks are what Plex emits.
 * Transcribed rather than decoded only so a test can override a single field;
 * new tests should load the fixture directly, as ReconcilePlexMoviesTest does.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function plexShowMetadata(array $overrides = []): array
{
    return [
        'ratingKey' => '34112',
        'guid' => 'plex://show/5d9c08713c3f87001f3505b6',
        'title' => '24',
        'year' => 2001,
        'leafCount' => 24,
        'childCount' => 1,
        'addedAt' => 1775193014,
        'updatedAt' => 1782985591,
        'Guid' => [
            ['id' => 'imdb://tt0285331'],
            ['id' => 'tmdb://1973'],
            ['id' => 'tvdb://76290'],
        ],
        ...$overrides,
    ];
}

/**
 * @return array<int, array<string, mixed>>
 */
function plexShowPayload(): array
{
    return [
        plexShowMetadata(),
        plexShowMetadata([
            'ratingKey' => '27520',
            'guid' => 'plex://show/65f48a3d97ad9a728e5208f5',
            'title' => 'Adolescence',
            'year' => 2025,
            'leafCount' => 4,
            'childCount' => 1,
            'addedAt' => 1742774000,
            'updatedAt' => 1784194023,
            'Guid' => [
                ['id' => 'imdb://tt31806037'],
                ['id' => 'tmdb://249042'],
                ['id' => 'tvdb://452467'],
            ],
        ]),
        plexShowMetadata([
            'ratingKey' => '32204',
            'guid' => 'plex://show/5d9f410f4441b1001fa1ab87',
            'title' => 'American Vandal',
            'year' => 2017,
            'leafCount' => 16,
            'childCount' => 2,
            'addedAt' => 1766625117,
            'updatedAt' => 1782552566,
            'Guid' => [
                ['id' => 'imdb://tt6877772'],
                ['id' => 'tmdb://73126'],
                ['id' => 'tvdb://332828'],
            ],
        ]),
    ];
}

it('inserts one row per page item with core _plex_ facts and returns the count', function (): void {
    // Arrange
    $server = PlexServer::factory()->create();
    $library = PlexLibrary::factory()->create(['plex_server_id' => $server->id]);
    $page = plexShowPayload();

    // Act
    $count = (new ReconcilePlexShows)->upsertPage($server, $library, $page, now());

    // Assert
    $this->assertDatabaseCount('plex_shows', 3);
    expect($count)->toBe(3);
    $row = PlexShow::query()->where('_plex_ratingKey', '34112')->firstOrFail();
    expect($row->_plex_ratingKey)->toBeString()->toBe('34112');
    expect($row->_plex_title)->toBe('24');
    expect($row->_plex_year)->toBe(2001);
    expect($row->_plex_leafCount)->toBe(24);
    expect($row->_plex_childCount)->toBe(1);
    expect($row->_plex_addedAt)->not->toBeNull();
    expect($row->_plex_updatedAt)->not->toBeNull();
    expect($row->_plex_guids)->toBe([
        ['id' => 'imdb://tt0285331'],
        ['id' => 'tmdb://1973'],
        ['id' => 'tvdb://76290'],
    ]);
});

it('materializes crosswalk ids from the Guid list', function (): void {
    // Arrange
    $server = PlexServer::factory()->create();
    $library = PlexLibrary::factory()->create(['plex_server_id' => $server->id]);
    $page = plexShowPayload();

    // Act
    (new ReconcilePlexShows)->upsertPage($server, $library, $page, now());

    // Assert
    $this->assertDatabaseCount('plex_shows', 3);
    $row = PlexShow::query()->where('_plex_ratingKey', '34112')->firstOrFail();
    expect($row->_imdb_id)->toBe('tt0285331');
    expect($row->_tmdb_id)->toBe(1973);
    expect($row->_tvdb_id)->toBe(76290);
});

it('nulls a malformed crosswalk id while still inserting the row', function (): void {
    // Arrange
    $server = PlexServer::factory()->create();
    $library = PlexLibrary::factory()->create(['plex_server_id' => $server->id]);
    $page = plexShowPayload();
    $page[] = plexShowMetadata([
        'ratingKey' => '99999',
        'guid' => 'plex://show/deadbeefdeadbeefdeadbeef',
        'title' => 'Garbage Crosswalk',
        'Guid' => [
            ['id' => 'imdb://tt0111161'],
            ['id' => 'tmdb://278'],
            ['id' => 'tvdb://1335814-slug'],
        ],
    ]);

    // Act
    (new ReconcilePlexShows)->upsertPage($server, $library, $page, now());

    // Assert
    $this->assertDatabaseCount('plex_shows', 4);
    $row = PlexShow::query()->where('_plex_ratingKey', '99999')->firstOrFail();
    expect($row->_tvdb_id)->toBeNull();
    expect($row->_imdb_id)->toBe('tt0111161');
    expect($row->_tmdb_id)->toBe(278);
});

it('scopes every row to the given server and library and stamps the given synced_at', function (): void {
    // Arrange
    $server = PlexServer::factory()->create();
    $library = PlexLibrary::factory()->create(['plex_server_id' => $server->id]);
    $page = plexShowPayload();
    $now = Date::parse('2026-02-03 04:05:06');

    // Act
    (new ReconcilePlexShows)->upsertPage($server, $library, $page, $now);

    // Assert
    $rows = PlexShow::query()->get();
    expect($rows)->toHaveCount(3);
    $rows->each(function (PlexShow $row) use ($library): void {
        expect($row->plex_server_id)->toBe($library->plex_server_id);
        expect($row->plex_library_id)->toBe($library->id);
        expect($row->synced_at?->toDateTimeString())->toBe('2026-02-03 04:05:06');
    });
});

it('re-running an identical page does not duplicate rows', function (): void {
    // Arrange
    $server = PlexServer::factory()->create();
    $library = PlexLibrary::factory()->create(['plex_server_id' => $server->id]);
    $page = plexShowPayload();
    (new ReconcilePlexShows)->upsertPage($server, $library, $page, now());

    // Act
    (new ReconcilePlexShows)->upsertPage($server, $library, $page, now());

    // Assert
    $this->assertDatabaseCount('plex_shows', 3);
    expect(PlexShow::query()->where('_plex_ratingKey', '34112')->count())->toBe(1);
});

it('overwrites a changed fact in place and re-stamps synced_at with the later pass clock', function (): void {
    // Arrange
    $server = PlexServer::factory()->create();
    $library = PlexLibrary::factory()->create(['plex_server_id' => $server->id]);
    $first = Date::parse('2026-02-03 04:05:06');
    (new ReconcilePlexShows)->upsertPage($server, $library, plexShowPayload(), $first);
    $changed = plexShowPayload();
    $changed[0] = plexShowMetadata(['title' => '24 (Remastered)', 'leafCount' => 48]);

    // Act
    (new ReconcilePlexShows)->upsertPage($server, $library, $changed, $first->copy()->addHour());

    // Assert
    expect(PlexShow::query()->where('plex_server_id', $server->id)->where('_plex_ratingKey', '34112')->count())->toBe(1);
    $row = PlexShow::query()->where('plex_server_id', $server->id)->where('_plex_ratingKey', '34112')->firstOrFail();
    expect($row->_plex_title)->toBe('24 (Remastered)');
    expect($row->_plex_leafCount)->toBe(48);
    expect($row->synced_at?->toDateTimeString())->toBe('2026-02-03 05:05:06');
});

it('hard-deletes a show stamped before the pass and spares one stamped at the pass clock', function (): void {
    // Arrange
    $this->freezeTime();
    $server = PlexServer::factory()->create();
    $library = PlexLibrary::factory()->create(['plex_server_id' => $server->id]);
    $now = now();
    staleShow($server, $library, '32204');
    staleShow($server, $library, '34112', syncedAt: $now);

    // Act
    (new ReconcilePlexShows)->prune($server, $library, $now);

    // Assert
    $this->assertDatabaseMissing('plex_shows', ['plex_server_id' => $server->id, '_plex_ratingKey' => '32204']);
    $this->assertDatabaseHas('plex_shows', ['plex_server_id' => $server->id, '_plex_ratingKey' => '34112']);
});

it('cascades to seasons and episodes when the prune deletes a stale show', function (): void {
    // Arrange
    $this->freezeTime();
    $server = PlexServer::factory()->create();
    $library = PlexLibrary::factory()->create(['plex_server_id' => $server->id]);
    $now = now();
    $vanishing = staleShow($server, $library, '32204');
    $season = PlexSeason::factory()->create(['plex_server_id' => $server->id, 'plex_show_id' => $vanishing->id]);
    PlexEpisode::factory()->create(['plex_server_id' => $server->id, 'plex_show_id' => $vanishing->id, 'plex_season_id' => $season->id]);
    staleShow($server, $library, '34112', syncedAt: $now);

    // Act
    (new ReconcilePlexShows)->prune($server, $library, $now);

    // Assert
    $this->assertDatabaseMissing('plex_shows', ['id' => $vanishing->id]);
    $this->assertDatabaseMissing('plex_seasons', ['plex_show_id' => $vanishing->id]);
    $this->assertDatabaseMissing('plex_episodes', ['plex_show_id' => $vanishing->id]);
});

it('prunes only within the reconciled server, sparing other servers', function (): void {
    // Arrange
    $this->freezeTime();
    $server = PlexServer::factory()->create();
    $library = PlexLibrary::factory()->create(['plex_server_id' => $server->id]);
    $now = now();
    $otherServer = PlexServer::factory()->create();
    $otherLibrary = PlexLibrary::factory()->create(['plex_server_id' => $otherServer->id]);
    // The bystander is stamped a minute back too, so it is stale by the `<`
    // test and only the server scoping can save it.
    $otherShow = staleShow($otherServer, $otherLibrary, '55555');
    staleShow($server, $library, '32204');

    // Act
    (new ReconcilePlexShows)->prune($server, $library, $now);

    // Assert
    $this->assertDatabaseMissing('plex_shows', ['plex_server_id' => $server->id, '_plex_ratingKey' => '32204']);
    $this->assertDatabaseHas('plex_shows', ['id' => $otherShow->id, 'plex_server_id' => $otherServer->id, '_plex_ratingKey' => '55555']);
});

it('prunes only within the reconciled library, sparing sibling libraries on the same server', function (): void {
    // Arrange
    $this->freezeTime();
    $server = PlexServer::factory()->create();
    $tvShows = PlexLibrary::factory()->create(['plex_server_id' => $server->id, '_plex_type' => 'show', '_plex_title' => 'TV Shows']);
    $anime = PlexLibrary::factory()->create(['plex_server_id' => $server->id, '_plex_type' => 'show', '_plex_title' => 'Anime']);
    $now = now();
    // Both libraries' rows are stale by the `<` test, so only the library
    // scoping can spare the anime ones.
    staleShow($server, $tvShows, '34112');
    staleShow($server, $anime, '41001');
    staleShow($server, $anime, '41002');

    // Act
    (new ReconcilePlexShows)->prune($server, $anime, $now);

    // Assert
    $this->assertDatabaseHas('plex_shows', ['plex_library_id' => $tvShows->id, '_plex_ratingKey' => '34112']);
    $this->assertDatabaseMissing('plex_shows', ['plex_library_id' => $anime->id, '_plex_ratingKey' => '41001']);
    $this->assertDatabaseMissing('plex_shows', ['plex_library_id' => $anime->id, '_plex_ratingKey' => '41002']);
    expect(PlexShow::query()->where('plex_library_id', $anime->id)->count())->toBe(0);
});

it('clears the reconciled library when the pass upserted no pages at all', function (): void {
    // Arrange
    $this->freezeTime();
    $server = PlexServer::factory()->create();
    $tvShows = PlexLibrary::factory()->create(['plex_server_id' => $server->id, '_plex_type' => 'show', '_plex_title' => 'TV Shows']);
    $anime = PlexLibrary::factory()->create(['plex_server_id' => $server->id, '_plex_type' => 'show', '_plex_title' => 'Anime']);
    $now = now();
    staleShow($server, $tvShows, '34112');
    staleShow($server, $tvShows, '27520');
    $animeShow = staleShow($server, $anime, '41001');

    // Act
    (new ReconcilePlexShows)->prune($server, $tvShows, $now);

    // Assert
    $this->assertDatabaseMissing('plex_shows', ['plex_library_id' => $tvShows->id, '_plex_ratingKey' => '34112']);
    $this->assertDatabaseMissing('plex_shows', ['plex_library_id' => $tvShows->id, '_plex_ratingKey' => '27520']);
    expect(PlexShow::query()->where('plex_library_id', $tvShows->id)->count())->toBe(0);
    $this->assertDatabaseHas('plex_shows', ['id' => $animeShow->id, 'plex_library_id' => $anime->id, '_plex_ratingKey' => '41001']);
});

it('cascades to seasons and episodes when the prune clears the whole library', function (): void {
    // Arrange
    $this->freezeTime();
    $server = PlexServer::factory()->create();
    $library = PlexLibrary::factory()->create(['plex_server_id' => $server->id]);
    $now = now();
    $cleared = staleShow($server, $library, '34112');
    $season = PlexSeason::factory()->create(['plex_server_id' => $server->id, 'plex_show_id' => $cleared->id]);
    PlexEpisode::factory()->create(['plex_server_id' => $server->id, 'plex_show_id' => $cleared->id, 'plex_season_id' => $season->id]);

    // Act
    (new ReconcilePlexShows)->prune($server, $library, $now);

    // Assert
    $this->assertDatabaseMissing('plex_shows', ['id' => $cleared->id]);
    $this->assertDatabaseMissing('plex_seasons', ['plex_show_id' => $cleared->id]);
    $this->assertDatabaseMissing('plex_episodes', ['plex_show_id' => $cleared->id]);
});

/*
|--------------------------------------------------------------------------
| A show that moved is MARKED, not collected: `upsertPage()` nulls
| `episodes_synced_at` on the shows whose `_plex_updatedAt`/`_plex_leafCount`
| moved, so the episode crawl set comes from the DB
| (`SyncPlexLibrary::showsToCrawl()` already ORs `whereNull('episodes_synced_at')`)
| instead of a run-long accumulator the caller has to carry across every page.
| Nulling costs a changed show its last-crawled watermark until the crawl
| re-stamps it — a watermark, not history, and the accepted price of paging.
|
| Every case below arranges its pre-existing show through `upsertPage()` rather
| than the factory, then stamps the watermark: the verdict is a string-vs-string
| comparison across the driver boundary (incoming `toDateTimeString()` vs
| whatever text the driver stored), and this suite is sqlite while prod is MySQL.
| Round-tripping through the production write path is the only way that
| comparison is proven rather than assumed — never hand-write the stored side.
|--------------------------------------------------------------------------
*/
it('leaves a newly inserted show with no episodes watermark', function (): void {
    // Arrange
    $server = PlexServer::factory()->create();
    $library = PlexLibrary::factory()->create(['plex_server_id' => $server->id]);
    $page = [plexShowMetadata()];

    // Act
    (new ReconcilePlexShows)->upsertPage($server, $library, $page, now());

    // Assert
    $row = PlexShow::query()->where('_plex_ratingKey', '34112')->firstOrFail();
    expect($row->episodes_synced_at)->toBeNull();
});

it('clears the episodes watermark of a show whose incoming updatedAt moved', function (): void {
    // Arrange
    $this->freezeTime();
    $server = PlexServer::factory()->create();
    $library = PlexLibrary::factory()->create(['plex_server_id' => $server->id]);
    $now = now();
    (new ReconcilePlexShows)->upsertPage($server, $library, [plexShowMetadata()], $now);
    PlexShow::query()->where('_plex_ratingKey', '34112')->update(['episodes_synced_at' => $now->copy()->subMinute()]);
    $moved = [plexShowMetadata(['updatedAt' => 1782985591 + 1000])];

    // Act
    (new ReconcilePlexShows)->upsertPage($server, $library, $moved, $now);

    // Assert
    $row = PlexShow::query()->where('_plex_ratingKey', '34112')->firstOrFail();
    expect($row->episodes_synced_at)->toBeNull();
});

it('clears the episodes watermark of a show whose incoming leafCount moved', function (): void {
    // Arrange
    $this->freezeTime();
    $server = PlexServer::factory()->create();
    $library = PlexLibrary::factory()->create(['plex_server_id' => $server->id]);
    $now = now();
    (new ReconcilePlexShows)->upsertPage($server, $library, [plexShowMetadata()], $now);
    PlexShow::query()->where('_plex_ratingKey', '34112')->update(['episodes_synced_at' => $now->copy()->subMinute()]);
    $moved = [plexShowMetadata(['leafCount' => 30])];

    // Act
    (new ReconcilePlexShows)->upsertPage($server, $library, $moved, $now);

    // Assert
    $row = PlexShow::query()->where('_plex_ratingKey', '34112')->firstOrFail();
    expect($row->episodes_synced_at)->toBeNull();
});

it('keeps the episodes watermark of a show unchanged on both updatedAt and leafCount', function (): void {
    // Arrange
    $this->freezeTime();
    $server = PlexServer::factory()->create();
    $library = PlexLibrary::factory()->create(['plex_server_id' => $server->id]);
    $now = now();
    (new ReconcilePlexShows)->upsertPage($server, $library, [plexShowMetadata()], $now);
    // Stamped a minute back, not at `$now`: `episodes_synced_at` is
    // second-precision, so a watermark equal to the pass clock could not tell an
    // untouched column apart from one the pass itself re-stamped.
    $crawled = $now->copy()->subMinute();
    PlexShow::query()->where('_plex_ratingKey', '34112')->update(['episodes_synced_at' => $crawled]);

    // Act
    (new ReconcilePlexShows)->upsertPage($server, $library, [plexShowMetadata()], $now);

    // Assert
    $row = PlexShow::query()->where('_plex_ratingKey', '34112')->firstOrFail();
    expect($row->episodes_synced_at?->toDateTimeString())->toBe($crawled->toDateTimeString());
});

it('clears the watermark on the pre-write snapshot though the row now stores the new updatedAt', function (): void {
    // Arrange
    $this->freezeTime();
    $server = PlexServer::factory()->create();
    $library = PlexLibrary::factory()->create(['plex_server_id' => $server->id]);
    $now = now();
    (new ReconcilePlexShows)->upsertPage($server, $library, [plexShowMetadata()], $now);
    PlexShow::query()->where('_plex_ratingKey', '34112')->update(['episodes_synced_at' => $now->copy()->subMinute()]);
    $newEpoch = 1782985591 + 1000;
    $moved = [plexShowMetadata(['updatedAt' => $newEpoch])];

    // Act
    (new ReconcilePlexShows)->upsertPage($server, $library, $moved, $now);

    // Assert
    $row = PlexShow::query()->where('_plex_ratingKey', '34112')->firstOrFail();
    expect($row->_plex_updatedAt?->getTimestamp())->toBe($newEpoch);
    expect($row->episodes_synced_at)->toBeNull();
});

/*
|--------------------------------------------------------------------------
| The malformed cases below unset a key from the real-capture-shaped item:
| Plex always emits guid/title on a show element, so a missing one can only be
| produced by hand. Both columns are NOT NULL, so the reconciler must fail while
| mapping the item — a QueryException would mean it coalesced the missing key to
| null and deferred the failure to the DB. The stakes are higher here than for
| seasons/episodes: a page goes through one bulk PlexShow::upsert() that
| PlexLibraryCommand does not guard, so a single bad row aborts the whole
| plex:seed / plex:sync run rather than writing a null show.
|--------------------------------------------------------------------------
*/
describe('malformed payload', function (): void {
    it('fails a show item missing the required guid without writing a null', function (): void {
        // Arrange
        $server = PlexServer::factory()->create();
        $library = PlexLibrary::factory()->create(['plex_server_id' => $server->id]);
        $show = plexShowMetadata();
        unset($show['guid']);

        // Act
        $thrown = rescue(
            fn (): int => (new ReconcilePlexShows)->upsertPage($server, $library, [$show], now()),
            fn (Throwable $e): Throwable => $e,
            report: false,
        );

        // Assert
        expect($thrown)->toBeInstanceOf(Throwable::class)
            ->and($thrown)->not->toBeInstanceOf(QueryException::class);
        $this->assertDatabaseCount('plex_shows', 0);
    });

    it('fails a show item missing the required title without writing a null', function (): void {
        // Arrange
        $server = PlexServer::factory()->create();
        $library = PlexLibrary::factory()->create(['plex_server_id' => $server->id]);
        $show = plexShowMetadata();
        unset($show['title']);

        // Act
        $thrown = rescue(
            fn (): int => (new ReconcilePlexShows)->upsertPage($server, $library, [$show], now()),
            fn (Throwable $e): Throwable => $e,
            report: false,
        );

        // Assert
        expect($thrown)->toBeInstanceOf(Throwable::class)
            ->and($thrown)->not->toBeInstanceOf(QueryException::class);
        $this->assertDatabaseCount('plex_shows', 0);
    });
});
