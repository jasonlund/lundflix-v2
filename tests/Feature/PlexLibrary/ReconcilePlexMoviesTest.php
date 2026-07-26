<?php

declare(strict_types=1);

use App\Domains\Catalog\Models\Movie;
use App\Domains\PlexLibrary\Actions\ReconcilePlexMovies;
use App\Domains\PlexLibrary\Models\PlexLibrary;
use App\Domains\PlexLibrary\Models\PlexMovie;
use App\Domains\PlexLibrary\Models\PlexServer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Input items are decoded Plex "section all" movie Metadata[], loaded
| byte-exact from tests/Fixtures/PlexLibrary/plex/section_movie_all_includeGuids.json
| — a real capture of 3 movies (The Apprentice / Backrooms / The Baltimorons)
| from .context/plex-captures/section_movie_all_includeGuids.json, with ONLY
| the non-stored `Media` subtree trimmed from each item. This is the native
| Plex wire shape the reconciler consumes, NOT a hand-fabricated array.
|--------------------------------------------------------------------------
*/

it('inserts one plex_movies row per Metadata item and returns the count', function (): void {
    // Arrange
    [$server, $library] = serverWithLibrary();
    $items = fixtureMovieItems();

    // Act
    $count = resolve(ReconcilePlexMovies::class)->handle($server, $library, $items);

    // Assert
    expect($count)->toBe(3)
        ->and(PlexMovie::query()->where('plex_server_id', $server->id)->count())->toBe(3);
});

it('stores _plex_ratingKey as a string, not coerced to int', function (): void {
    // Arrange
    [$server, $library] = serverWithLibrary();
    $items = fixtureMovieItems();

    // Act
    resolve(ReconcilePlexMovies::class)->handle($server, $library, $items);

    // Assert
    $ratingKey = DB::table('plex_movies')->where('_plex_title', 'The Apprentice')->value('_plex_ratingKey');
    expect($ratingKey)->toBe('26278');
});

it('materializes crosswalk ids from the Guid array', function (): void {
    // Arrange
    [$server, $library] = serverWithLibrary();
    $items = fixtureMovieItems();

    // Act
    resolve(ReconcilePlexMovies::class)->handle($server, $library, $items);

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
    resolve(ReconcilePlexMovies::class)->handle($server, $library, $items);

    // Assert
    $guids = DB::table('plex_movies')->where('_plex_ratingKey', '26278')->value('_plex_guids');
    expect($guids)->toBe(json_encode($apprentice['Guid']));
});

it('maps _plex_* raw facts and stamps synced_at', function (): void {
    // Arrange
    [$server, $library] = serverWithLibrary();
    $items = fixtureMovieItems();

    // Act
    resolve(ReconcilePlexMovies::class)->handle($server, $library, $items);

    // Assert
    $movie = PlexMovie::query()->where('_plex_ratingKey', '26278')->first();
    expect($movie?->_plex_guid)->toBe('plex://movie/5f40d7a0427eeb0041f4d0f6')
        ->and($movie?->_plex_title)->toBe('The Apprentice')
        ->and($movie?->_plex_year)->toBe(2024)
        ->and($movie?->_plex_addedAt?->timestamp)->toBe(1733599154)
        ->and($movie?->_plex_updatedAt?->timestamp)->toBe(1783847803)
        ->and($movie?->synced_at)->not->toBeNull();
});

it('upserts idempotently: re-running the same item leaves exactly one row', function (): void {
    // Arrange
    [$server, $library] = serverWithLibrary();
    $item = apprenticeItem();
    resolve(ReconcilePlexMovies::class)->handle($server, $library, [$item]);

    // Act
    resolve(ReconcilePlexMovies::class)->handle($server, $library, [$item]);

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
    resolve(ReconcilePlexMovies::class)->handle($server, $library, [$item]);
    $edited = ['title' => 'The Apprentice (Director\'s Cut)', 'year' => 2025] + $item;

    // Act
    resolve(ReconcilePlexMovies::class)->handle($server, $library, [$edited]);

    // Assert
    $movies = PlexMovie::query()->where('_plex_ratingKey', '26278')->get();
    expect($movies)->toHaveCount(1)
        ->and($movies->first()->_plex_title)->toBe('The Apprentice (Director\'s Cut)')
        ->and($movies->first()->_plex_year)->toBe(2025);
});

it('re-stamps synced_at on a subsequent reconcile', function (): void {
    // Arrange
    [$server, $library] = serverWithLibrary();
    $item = apprenticeItem();
    resolve(ReconcilePlexMovies::class)->handle($server, $library, [$item]);
    $firstStamp = DB::table('plex_movies')->where('_plex_ratingKey', '26278')->value('synced_at');

    // Act
    $this->travel(1)->hour();
    resolve(ReconcilePlexMovies::class)->handle($server, $library, [$item]);

    // Assert
    $secondStamp = DB::table('plex_movies')->where('_plex_ratingKey', '26278')->value('synced_at');
    expect($secondStamp)->toBeGreaterThan($firstStamp);
});

it('hard-deletes a row that vanished from the payload', function (): void {
    // Arrange
    [$server, $library] = serverWithLibrary();
    PlexMovie::factory()->create(['plex_server_id' => $server->id, 'plex_library_id' => $library->id, '_plex_ratingKey' => 'AAA']);
    PlexMovie::factory()->create(['plex_server_id' => $server->id, 'plex_library_id' => $library->id, '_plex_ratingKey' => 'BBB']);
    $item = ['ratingKey' => 'AAA', 'title' => 'Kept'] + apprenticeItem();

    // Act
    resolve(ReconcilePlexMovies::class)->handle($server, $library, [$item]);

    // Assert
    $this->assertDatabaseMissing('plex_movies', ['plex_server_id' => $server->id, '_plex_ratingKey' => 'BBB']);
    $this->assertDatabaseHas('plex_movies', ['plex_server_id' => $server->id, '_plex_ratingKey' => 'AAA']);
});

it('scopes the prune to the reconciled server and library', function (): void {
    // Arrange
    [$server, $library] = serverWithLibrary();
    $otherLibrary = PlexLibrary::factory()->create(['plex_server_id' => $server->id]);
    PlexMovie::factory()->create(['plex_server_id' => $server->id, 'plex_library_id' => $otherLibrary->id, '_plex_ratingKey' => 'OTHERLIB']);
    [$otherServer, $otherServerLibrary] = serverWithLibrary();
    PlexMovie::factory()->create(['plex_server_id' => $otherServer->id, 'plex_library_id' => $otherServerLibrary->id, '_plex_ratingKey' => 'OTHERSRV']);
    $item = ['ratingKey' => 'AAA', 'title' => 'Kept'] + apprenticeItem();

    // Act
    resolve(ReconcilePlexMovies::class)->handle($server, $library, [$item]);

    // Assert
    $this->assertDatabaseHas('plex_movies', ['plex_server_id' => $server->id, '_plex_ratingKey' => 'OTHERLIB']);
    $this->assertDatabaseHas('plex_movies', ['plex_server_id' => $otherServer->id, '_plex_ratingKey' => 'OTHERSRV']);
});

it('clears the reconciled library when the payload is empty', function (): void {
    // Arrange
    [$server, $library] = serverWithLibrary();
    PlexMovie::factory()->create(['plex_server_id' => $server->id, 'plex_library_id' => $library->id, '_plex_ratingKey' => 'AAA']);
    PlexMovie::factory()->create(['plex_server_id' => $server->id, 'plex_library_id' => $library->id, '_plex_ratingKey' => 'BBB']);
    $otherLibrary = PlexLibrary::factory()->create(['plex_server_id' => $server->id]);
    PlexMovie::factory()->create(['plex_server_id' => $server->id, 'plex_library_id' => $otherLibrary->id, '_plex_ratingKey' => 'OTHERLIB']);

    // Act
    resolve(ReconcilePlexMovies::class)->handle($server, $library, []);

    // Assert
    $this->assertDatabaseMissing('plex_movies', ['plex_server_id' => $server->id, '_plex_ratingKey' => 'AAA']);
    $this->assertDatabaseMissing('plex_movies', ['plex_server_id' => $server->id, '_plex_ratingKey' => 'BBB']);
    $this->assertDatabaseHas('plex_movies', ['plex_server_id' => $server->id, '_plex_ratingKey' => 'OTHERLIB']);
});

it('persists an unmatched item with a null movie relation', function (): void {
    // Arrange
    [$server, $library] = serverWithLibrary();
    $item = apprenticeItem();

    // Act
    resolve(ReconcilePlexMovies::class)->handle($server, $library, [$item]);

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
    resolve(ReconcilePlexMovies::class)->handle($server, $library, [apprenticeItem()]);

    // Assert
    $plexMovie = PlexMovie::query()->where('_plex_ratingKey', '26278')->first();
    expect($plexMovie->movie->is($movie))->toBeTrue();
});

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
