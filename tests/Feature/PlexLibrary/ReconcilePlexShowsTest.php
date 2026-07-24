<?php

declare(strict_types=1);

use App\Domains\PlexLibrary\Actions\ReconcilePlexShows;
use App\Domains\PlexLibrary\Models\PlexEpisode;
use App\Domains\PlexLibrary\Models\PlexLibrary;
use App\Domains\PlexLibrary\Models\PlexSeason;
use App\Domains\PlexLibrary\Models\PlexServer;
use App\Domains\PlexLibrary\Models\PlexShow;
use Illuminate\Support\Facades\Date;

/**
 * Show Metadata items constructed inline from the real Plex capture
 * (.context/plex-captures/section_show_all_includeGuids.json) so the mapped
 * facts and Guid[] crosswalks match what the API actually emits.
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

it('inserts one row per Metadata item with core _plex_ facts', function (): void {
    // Arrange
    $server = PlexServer::factory()->create();
    $library = PlexLibrary::factory()->create(['plex_server_id' => $server->id]);
    $payload = plexShowPayload();

    // Act
    (new ReconcilePlexShows)->handle($server, $library, $payload);

    // Assert
    $this->assertDatabaseCount('plex_shows', 3);
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
    $payload = plexShowPayload();

    // Act
    (new ReconcilePlexShows)->handle($server, $library, $payload);

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
    $payload = plexShowPayload();
    $payload[] = plexShowMetadata([
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
    (new ReconcilePlexShows)->handle($server, $library, $payload);

    // Assert
    $this->assertDatabaseCount('plex_shows', 4);
    $row = PlexShow::query()->where('_plex_ratingKey', '99999')->firstOrFail();
    expect($row->_tvdb_id)->toBeNull();
    expect($row->_imdb_id)->toBe('tt0111161');
    expect($row->_tmdb_id)->toBe(278);
});

it('scopes every row to the given server and library and stamps synced_at', function (): void {
    // Arrange
    $server = PlexServer::factory()->create();
    $library = PlexLibrary::factory()->create(['plex_server_id' => $server->id]);
    $payload = plexShowPayload();

    // Act
    (new ReconcilePlexShows)->handle($server, $library, $payload);

    // Assert
    $rows = PlexShow::query()->get();
    expect($rows)->toHaveCount(3);
    $rows->each(function (PlexShow $row) use ($library): void {
        expect($row->plex_server_id)->toBe($library->plex_server_id);
        expect($row->plex_library_id)->toBe($library->id);
        expect($row->synced_at)->not->toBeNull();
    });
});

it('re-running an identical payload does not duplicate rows', function (): void {
    // Arrange
    $server = PlexServer::factory()->create();
    $library = PlexLibrary::factory()->create(['plex_server_id' => $server->id]);
    $payload = plexShowPayload();
    (new ReconcilePlexShows)->handle($server, $library, $payload);

    // Act
    (new ReconcilePlexShows)->handle($server, $library, $payload);

    // Assert
    $this->assertDatabaseCount('plex_shows', 3);
    expect(PlexShow::query()->where('_plex_ratingKey', '34112')->count())->toBe(1);
});

it('overwrites a changed fact in place instead of duplicating', function (): void {
    // Arrange
    $server = PlexServer::factory()->create();
    $library = PlexLibrary::factory()->create(['plex_server_id' => $server->id]);
    (new ReconcilePlexShows)->handle($server, $library, plexShowPayload());
    $changed = plexShowPayload();
    $changed[0] = plexShowMetadata(['title' => '24 (Remastered)', 'leafCount' => 48]);

    // Act
    (new ReconcilePlexShows)->handle($server, $library, $changed);

    // Assert
    expect(PlexShow::query()->where('plex_server_id', $server->id)->where('_plex_ratingKey', '34112')->count())->toBe(1);
    $row = PlexShow::query()->where('plex_server_id', $server->id)->where('_plex_ratingKey', '34112')->firstOrFail();
    expect($row->_plex_title)->toBe('24 (Remastered)');
    expect($row->_plex_leafCount)->toBe(48);
});

it('hard-deletes a show absent from the incoming payload', function (): void {
    // Arrange
    $server = PlexServer::factory()->create();
    $library = PlexLibrary::factory()->create(['plex_server_id' => $server->id]);
    (new ReconcilePlexShows)->handle($server, $library, plexShowPayload());
    $shrunk = array_values(array_filter(plexShowPayload(), fn (array $item): bool => $item['ratingKey'] !== '32204'));

    // Act
    (new ReconcilePlexShows)->handle($server, $library, $shrunk);

    // Assert
    $this->assertDatabaseMissing('plex_shows', ['plex_server_id' => $server->id, '_plex_ratingKey' => '32204']);
    $this->assertDatabaseHas('plex_shows', ['plex_server_id' => $server->id, '_plex_ratingKey' => '34112']);
    $this->assertDatabaseHas('plex_shows', ['plex_server_id' => $server->id, '_plex_ratingKey' => '27520']);
});

it('cascades to seasons and episodes when a vanished show is deleted', function (): void {
    // Arrange
    $server = PlexServer::factory()->create();
    $library = PlexLibrary::factory()->create(['plex_server_id' => $server->id]);
    (new ReconcilePlexShows)->handle($server, $library, plexShowPayload());
    $vanishing = PlexShow::query()->where('plex_server_id', $server->id)->where('_plex_ratingKey', '32204')->firstOrFail();
    $season = PlexSeason::factory()->create(['plex_server_id' => $server->id, 'plex_show_id' => $vanishing->id]);
    PlexEpisode::factory()->create(['plex_server_id' => $server->id, 'plex_show_id' => $vanishing->id, 'plex_season_id' => $season->id]);
    $shrunk = array_values(array_filter(plexShowPayload(), fn (array $item): bool => $item['ratingKey'] !== '32204'));

    // Act
    (new ReconcilePlexShows)->handle($server, $library, $shrunk);

    // Assert
    $this->assertDatabaseMissing('plex_shows', ['id' => $vanishing->id]);
    $this->assertDatabaseMissing('plex_seasons', ['plex_show_id' => $vanishing->id]);
    $this->assertDatabaseMissing('plex_episodes', ['plex_show_id' => $vanishing->id]);
});

it('prunes only within the reconciled server, sparing other servers', function (): void {
    // Arrange
    $server = PlexServer::factory()->create();
    $library = PlexLibrary::factory()->create(['plex_server_id' => $server->id]);
    $otherServer = PlexServer::factory()->create();
    $otherLibrary = PlexLibrary::factory()->create(['plex_server_id' => $otherServer->id]);
    $otherShow = PlexShow::factory()->create([
        'plex_server_id' => $otherServer->id,
        'plex_library_id' => $otherLibrary->id,
        '_plex_ratingKey' => '55555',
    ]);

    // Act
    (new ReconcilePlexShows)->handle($server, $library, plexShowPayload());

    // Assert
    $this->assertDatabaseHas('plex_shows', ['id' => $otherShow->id, 'plex_server_id' => $otherServer->id, '_plex_ratingKey' => '55555']);
});

it('returns a newly inserted show carrying its ratingKey and persisted row id', function (): void {
    // Arrange
    $server = PlexServer::factory()->create();
    $library = PlexLibrary::factory()->create(['plex_server_id' => $server->id]);
    $payload = [plexShowMetadata()];

    // Act
    $changed = (new ReconcilePlexShows)->handle($server, $library, $payload);

    // Assert
    $entry = collect($changed)->firstWhere('_plex_ratingKey', '34112');
    $rowId = PlexShow::query()->where('_plex_ratingKey', '34112')->value('id');
    expect($entry)->not->toBeNull();
    expect($entry['_plex_ratingKey'])->toBe('34112');
    expect($entry['id'])->toBe($rowId);
});

it('includes a show whose incoming updatedAt moved', function (): void {
    // Arrange
    $server = PlexServer::factory()->create();
    $library = PlexLibrary::factory()->create(['plex_server_id' => $server->id]);
    (new ReconcilePlexShows)->handle($server, $library, [plexShowMetadata()]);
    $moved = [plexShowMetadata(['updatedAt' => 1782985591 + 1000])];

    // Act
    $changed = (new ReconcilePlexShows)->handle($server, $library, $moved);

    // Assert
    expect(collect($changed)->firstWhere('_plex_ratingKey', '34112'))->not->toBeNull();
});

it('includes a show whose incoming leafCount moved', function (): void {
    // Arrange
    $server = PlexServer::factory()->create();
    $library = PlexLibrary::factory()->create(['plex_server_id' => $server->id]);
    (new ReconcilePlexShows)->handle($server, $library, [plexShowMetadata()]);
    $moved = [plexShowMetadata(['leafCount' => 30])];

    // Act
    $changed = (new ReconcilePlexShows)->handle($server, $library, $moved);

    // Assert
    expect(collect($changed)->firstWhere('_plex_ratingKey', '34112'))->not->toBeNull();
});

it('excludes a show unchanged on both updatedAt and leafCount', function (): void {
    // Arrange
    $server = PlexServer::factory()->create();
    $library = PlexLibrary::factory()->create(['plex_server_id' => $server->id]);
    (new ReconcilePlexShows)->handle($server, $library, [plexShowMetadata()]);

    // Act
    $changed = (new ReconcilePlexShows)->handle($server, $library, [plexShowMetadata()]);

    // Assert
    expect(collect($changed)->firstWhere('_plex_ratingKey', '34112'))->toBeNull();
});

it('reports an updatedAt-moved show though the row now stores the new value', function (): void {
    // Arrange
    $server = PlexServer::factory()->create();
    $library = PlexLibrary::factory()->create(['plex_server_id' => $server->id]);
    (new ReconcilePlexShows)->handle($server, $library, [plexShowMetadata()]);
    $newEpoch = 1782985591 + 1000;
    $moved = [plexShowMetadata(['updatedAt' => $newEpoch])];

    // Act
    $changed = (new ReconcilePlexShows)->handle($server, $library, $moved);

    // Assert
    expect(collect($changed)->firstWhere('_plex_ratingKey', '34112'))->not->toBeNull();
    $row = PlexShow::query()->where('_plex_ratingKey', '34112')->firstOrFail();
    expect($row->_plex_updatedAt->toDateTimeString())->toBe(Date::createFromTimestamp($newEpoch)->toDateTimeString());
});
