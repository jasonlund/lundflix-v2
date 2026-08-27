<?php

declare(strict_types=1);

use App\Domains\Catalog\Models\Movie;
use App\Domains\PlexLibrary\Actions\ReconcilePlexMovies;
use App\Domains\PlexLibrary\Models\PlexLibrary;
use App\Domains\PlexLibrary\Models\PlexMovie;
use App\Domains\PlexLibrary\Models\PlexServer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| The reconciler is page-at-a-time mark-and-sweep: `upsertPage()` writes one
| page of Metadata stamped with the caller's `$now` and NEVER deletes, then a
| single `prune()` sweeps whatever this server+library still carries an older
| stamp than that same `$now`. `$now` is the injected clock seam — the action
| never reads the clock itself, so every case asserts the exact stamp handed in.
|
| Input items are decoded Plex "section all" movie Metadata[], loaded
| byte-exact from tests/Fixtures/PlexLibrary/plex/section_movie_all_includeGuids.json
| — a real capture of 3 movies (The Apprentice / Backrooms / The Baltimorons)
| from .context/plex-captures/section_movie_all_includeGuids.json, with ONLY
| the non-stored `Media` subtree trimmed from each item. This is the native
| Plex wire shape the reconciler consumes, NOT a hand-fabricated array.
|--------------------------------------------------------------------------
*/

/**
 * A saved Plex server plus a library that belongs to it — the two-row setup
 * every reconcile test needs.
 *
 * @return array{0: PlexServer, 1: PlexLibrary}
 */
function serverWithLibrary(): array
{
    $server = PlexServer::factory()->create();
    $library = PlexLibrary::factory()->create(['plex_server_id' => $server->id]);

    return [$server, $library];
}

/**
 * The 3 real Plex movie Metadata items, decoded byte-exact from the committed
 * fixture (Media subtree already trimmed).
 *
 * @return array<int, array<string, mixed>>
 */
function fixtureMovieItems(): array
{
    return json_decode(fixtureBytes('PlexLibrary/plex/section_movie_all_includeGuids.json'), true)['MediaContainer']['Metadata'];
}

/**
 * The single "The Apprentice" item (ratingKey 26278) sliced from the fixture —
 * the fixed subject of the idempotency/edit-in-place cases.
 *
 * @return array<string, mixed>
 */
function apprenticeItem(): array
{
    return collect(fixtureMovieItems())->firstWhere('ratingKey', '26278');
}

describe('upsertPage() page writes', function (): void {
    it('inserts one plex_movies row per page item, stamps the given synced_at, and returns the count', function (): void {
        // Arrange
        [$server, $library] = serverWithLibrary();
        $items = fixtureMovieItems();
        $now = Date::parse('2026-02-03 04:05:06');

        // Act
        $count = resolve(ReconcilePlexMovies::class)->upsertPage($server, $library, $items, $now);

        // Assert
        expect($count)->toBe(3)
            ->and(PlexMovie::query()->where('plex_server_id', $server->id)->count())->toBe(3)
            ->and(PlexMovie::query()->where('synced_at', '2026-02-03 04:05:06')->count())->toBe(3);
    });

    it('keeps every page of a multi-page pass alive through the following prune', function (): void {
        // Arrange
        $this->freezeTime();
        [$server, $library] = serverWithLibrary();
        $items = collect(fixtureMovieItems());
        $now = now();
        resolve(ReconcilePlexMovies::class)->upsertPage($server, $library, $items->take(2)->all(), $now);
        resolve(ReconcilePlexMovies::class)->upsertPage($server, $library, $items->skip(2)->all(), $now);

        // Act
        resolve(ReconcilePlexMovies::class)->prune($server, $library, $now);

        // Assert
        expect(PlexMovie::query()->where('plex_library_id', $library->id)->count())->toBe(3);
    });

    it('deletes nothing on its own: a stale row absent from the page survives upsertPage', function (): void {
        // Arrange
        $this->freezeTime();
        [$server, $library] = serverWithLibrary();
        $now = now();
        staleMovie($server, $library, 'STALE');

        // Act
        resolve(ReconcilePlexMovies::class)->upsertPage($server, $library, [apprenticeItem()], $now);

        // Assert
        $this->assertDatabaseHas('plex_movies', ['plex_server_id' => $server->id, '_plex_ratingKey' => 'STALE']);
        $this->assertDatabaseHas('plex_movies', ['plex_server_id' => $server->id, '_plex_ratingKey' => '26278']);
    });

});

describe('upsertPage() column mapping', function (): void {
    it('stores _plex_ratingKey as a string, not coerced to int', function (): void {
        // Arrange
        [$server, $library] = serverWithLibrary();
        $items = fixtureMovieItems();

        // Act
        resolve(ReconcilePlexMovies::class)->upsertPage($server, $library, $items, now());

        // Assert
        $ratingKey = DB::table('plex_movies')->where('_plex_title', 'The Apprentice')->value('_plex_ratingKey');
        expect($ratingKey)->toBe('26278');
    });

    it('materializes crosswalk ids from the Guid array', function (): void {
        // Arrange
        [$server, $library] = serverWithLibrary();
        $items = fixtureMovieItems();

        // Act
        resolve(ReconcilePlexMovies::class)->upsertPage($server, $library, $items, now());

        // Assert
        $movie = PlexMovie::query()->where('_plex_ratingKey', '26278')->first();
        expect($movie?->_imdb_id)->toBe('tt8368368')
            ->and($movie?->_tmdb_id)->toBe(1182047)
            ->and($movie?->_tvdb_id)->toBe(351923);
    });

    it('stores _plex_guids as the raw Guid json byte-for-byte', function (): void {
        // Arrange
        [$server, $library] = serverWithLibrary();
        $items = fixtureMovieItems();
        $apprentice = apprenticeItem();

        // Act
        resolve(ReconcilePlexMovies::class)->upsertPage($server, $library, $items, now());

        // Assert
        $guids = DB::table('plex_movies')->where('_plex_ratingKey', '26278')->value('_plex_guids');
        expect($guids)->toBe(json_encode($apprentice['Guid']));
    });

    it('maps _plex_* raw facts and stamps synced_at', function (): void {
        // Arrange
        [$server, $library] = serverWithLibrary();
        $items = fixtureMovieItems();

        // Act
        resolve(ReconcilePlexMovies::class)->upsertPage($server, $library, $items, now());

        // Assert
        $movie = PlexMovie::query()->where('_plex_ratingKey', '26278')->first();
        expect($movie?->_plex_guid)->toBe('plex://movie/5f40d7a0427eeb0041f4d0f6')
            ->and($movie?->_plex_title)->toBe('The Apprentice')
            ->and($movie?->_plex_year)->toBe(2024)
            ->and($movie?->_plex_addedAt?->timestamp)->toBe(1733599154)
            ->and($movie?->_plex_updatedAt?->timestamp)->toBe(1783847803)
            ->and($movie?->synced_at)->not->toBeNull();
    });

});

describe('upsertPage() re-runs', function (): void {
    it('upserts idempotently: re-running the same item leaves exactly one row', function (): void {
        // Arrange
        [$server, $library] = serverWithLibrary();
        $item = apprenticeItem();
        resolve(ReconcilePlexMovies::class)->upsertPage($server, $library, [$item], now());

        // Act
        resolve(ReconcilePlexMovies::class)->upsertPage($server, $library, [$item], now());

        // Assert
        expect(PlexMovie::query()
            ->where('plex_server_id', $server->id)
            ->where('_plex_ratingKey', '26278')
            ->count())->toBe(1);
    });

    it('edits in place: a changed title/year overwrites on the composite key', function (): void {
        // Arrange
        [$server, $library] = serverWithLibrary();
        $item = apprenticeItem();
        resolve(ReconcilePlexMovies::class)->upsertPage($server, $library, [$item], now());
        $edited = ['title' => 'The Apprentice (Director\'s Cut)', 'year' => 2025] + $item;

        // Act
        resolve(ReconcilePlexMovies::class)->upsertPage($server, $library, [$edited], now());

        // Assert
        $movies = PlexMovie::query()->where('_plex_ratingKey', '26278')->get();
        expect($movies)->toHaveCount(1)
            ->and($movies->first()->_plex_title)->toBe('The Apprentice (Director\'s Cut)')
            ->and($movies->first()->_plex_year)->toBe(2025);
    });

    it('re-stamps synced_at with the clock of the later pass', function (): void {
        // Arrange
        [$server, $library] = serverWithLibrary();
        $item = apprenticeItem();
        $first = Date::parse('2026-02-03 04:05:06');
        resolve(ReconcilePlexMovies::class)->upsertPage($server, $library, [$item], $first);

        // Act
        resolve(ReconcilePlexMovies::class)->upsertPage($server, $library, [$item], $first->copy()->addHour());

        // Assert
        $stamp = DB::table('plex_movies')->where('_plex_ratingKey', '26278')->value('synced_at');
        expect($stamp)->toBe('2026-02-03 05:05:06');
    });

});

describe('upsertPage() catalog link', function (): void {
    it('persists an unmatched item with a null movie relation', function (): void {
        // Arrange
        [$server, $library] = serverWithLibrary();
        $item = apprenticeItem();

        // Act
        resolve(ReconcilePlexMovies::class)->upsertPage($server, $library, [$item], now());

        // Assert
        $this->assertDatabaseHas('plex_movies', ['_plex_ratingKey' => '26278']);
        expect(Movie::query()->count())->toBe(0)
            ->and(PlexMovie::query()->where('_plex_ratingKey', '26278')->first()->movie)->toBeNull();
    });

    it('links to the catalog Movie sharing _tmdb_id', function (): void {
        // Arrange
        $movie = Movie::factory()->create(['_tmdb_id' => 1182047]);
        [$server, $library] = serverWithLibrary();

        // Act
        resolve(ReconcilePlexMovies::class)->upsertPage($server, $library, [apprenticeItem()], now());

        // Assert
        $plexMovie = PlexMovie::query()->where('_plex_ratingKey', '26278')->first();
        expect($plexMovie?->movie?->is($movie))->toBeTrue();
    });

});

describe('prune() sweep', function (): void {
    it('prunes a row stamped before the pass and spares the one the pass wrote', function (): void {
        // Arrange
        $this->freezeTime();
        [$server, $library] = serverWithLibrary();
        $now = now();
        staleMovie($server, $library, 'VANISHED');
        resolve(ReconcilePlexMovies::class)->upsertPage($server, $library, [apprenticeItem()], $now);

        // Act
        resolve(ReconcilePlexMovies::class)->prune($server, $library, $now);

        // Assert
        $this->assertDatabaseMissing('plex_movies', ['plex_server_id' => $server->id, '_plex_ratingKey' => 'VANISHED']);
        $this->assertDatabaseHas('plex_movies', ['plex_server_id' => $server->id, '_plex_ratingKey' => '26278']);
    });

    it('scopes the prune to the reconciled server and library', function (): void {
        // Arrange
        $this->freezeTime();
        [$server, $library] = serverWithLibrary();
        $now = now();
        $otherLibrary = PlexLibrary::factory()->create(['plex_server_id' => $server->id]);
        // Both bystanders are stamped a minute back, so they are stale by the `<`
        // test and only the server+library scoping can save them.
        staleMovie($server, $otherLibrary, 'OTHERLIB');
        [$otherServer, $otherServerLibrary] = serverWithLibrary();
        staleMovie($otherServer, $otherServerLibrary, 'OTHERSRV');
        staleMovie($server, $library, 'VANISHED');

        // Act
        resolve(ReconcilePlexMovies::class)->prune($server, $library, $now);

        // Assert
        $this->assertDatabaseMissing('plex_movies', ['plex_server_id' => $server->id, '_plex_ratingKey' => 'VANISHED']);
        $this->assertDatabaseHas('plex_movies', ['plex_server_id' => $server->id, '_plex_ratingKey' => 'OTHERLIB']);
        $this->assertDatabaseHas('plex_movies', ['plex_server_id' => $otherServer->id, '_plex_ratingKey' => 'OTHERSRV']);
    });

    it('clears the reconciled library when the pass upserted no pages at all', function (): void {
        // Arrange
        $this->freezeTime();
        [$server, $library] = serverWithLibrary();
        $now = now();
        staleMovie($server, $library, 'AAA');
        staleMovie($server, $library, 'BBB');
        $otherLibrary = PlexLibrary::factory()->create(['plex_server_id' => $server->id]);
        staleMovie($server, $otherLibrary, 'OTHERLIB');

        // Act
        resolve(ReconcilePlexMovies::class)->prune($server, $library, $now);

        // Assert
        $this->assertDatabaseMissing('plex_movies', ['plex_server_id' => $server->id, '_plex_ratingKey' => 'AAA']);
        $this->assertDatabaseMissing('plex_movies', ['plex_server_id' => $server->id, '_plex_ratingKey' => 'BBB']);
        $this->assertDatabaseHas('plex_movies', ['plex_server_id' => $server->id, '_plex_ratingKey' => 'OTHERLIB']);
    });

    it('spares a row stamped at exactly the pass clock', function (): void {
        // Arrange
        $this->freezeTime();
        [$server, $library] = serverWithLibrary();
        $now = now();
        staleMovie($server, $library, 'ONTHEDOT', syncedAt: $now);

        // Act
        resolve(ReconcilePlexMovies::class)->prune($server, $library, $now);

        // Assert
        $this->assertDatabaseHas('plex_movies', ['plex_server_id' => $server->id, '_plex_ratingKey' => 'ONTHEDOT']);
    });
});
