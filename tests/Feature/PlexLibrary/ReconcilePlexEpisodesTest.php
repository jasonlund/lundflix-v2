<?php

declare(strict_types=1);

use App\Domains\PlexLibrary\Actions\ReconcilePlexEpisodes;
use App\Domains\PlexLibrary\Models\PlexEpisode;
use App\Domains\PlexLibrary\Models\PlexSeason;
use App\Domains\PlexLibrary\Models\PlexShow;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| $children is the decoded Plex season MediaContainer.Metadata[] from the
| show /children endpoint. The real Season-2 row is loaded byte-exact from
| tests/Fixtures/PlexLibrary/plex/show_children_seasons.json — a real `24`
| capture (ratingKey 34424, index 2, tvdb://10064). This is the native Plex
| wire shape the reconciler consumes.
|
| The Specials (index 0) and "All episodes" aggregate (index -1) rows are
| HAND-AUTHORED synthetic Metadata lines — Plex's real `24` capture had no
| Specials, so those cases can't come from the byte-exact fixture. They carry
| only the stored keys the reconciler reads, so a failure lands on the
| behavior assertion, not a missing-key crash.
|
| $allLeaves is the decoded episode MediaContainer.Metadata[] from the show
| /allLeaves endpoint. Loaded byte-exact from
| tests/Fixtures/PlexLibrary/plex/show_allLeaves_episodes.json — a real `24`
| capture of all 24 Season-2 episodes; the episode tests below array_slice a
| 2-episode working set (ratingKeys 34425/34426, both parentRatingKey 34424)
| so they resolve to the fixture's Season-2 row. The fixture is never mutated.
|
| The "episode → season link" section below adds two HAND-AUTHORED synthetic
| inputs the byte-exact capture can't supply: a synthetic Specials episode
| (parentRatingKey 99000, parentIndex 0) paired with the synthetic Specials
| season, and an orphan case where a real episode's parentRatingKey is
| overridden to 88888 (a key absent from the season set) to prove an
| unmatched episode still persists with a null plex_season_id.
|
| The "malformed payload" section unsets a key from a synthetic line: Plex
| always emits guid/index/parentIndex/title on these elements, so a missing
| one can only be produced by hand. The columns behind them are NOT NULL, so
| the reconciler must fail while mapping the item — a QueryException would mean
| it coalesced the missing key to null and deferred the failure to the DB.
|--------------------------------------------------------------------------
*/

/**
 * The real Plex Season-2 Metadata row, decoded byte-exact from the committed
 * fixture.
 *
 * @return array<int, array<string, mixed>>
 */
function fixtureSeasonMetadata(): array
{
    return json_decode(fixtureBytes('PlexLibrary/plex/show_children_seasons.json'), true)['MediaContainer']['Metadata'];
}

/**
 * The real Plex Season-2 episode Metadata rows (all 24), decoded byte-exact
 * from the committed fixture. Callers array_slice a small working set.
 *
 * @return array<int, array<string, mixed>>
 */
function fixtureEpisodeMetadata(): array
{
    return json_decode(fixtureBytes('PlexLibrary/plex/show_allLeaves_episodes.json'), true)['MediaContainer']['Metadata'];
}

/**
 * A single synthetic Plex season Metadata line for the Specials/aggregate
 * cases the real `24` capture can't provide — only the stored keys the
 * reconciler reads. Keys are raw Plex wire keys.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function seasonMetadata(array $overrides = []): array
{
    return array_merge([
        'ratingKey' => '99000',
        'guid' => 'plex://season/synthetic00000000000000',
        'type' => 'season',
        'index' => 0,
        'title' => 'Specials',
        'leafCount' => 3,
        'addedAt' => 1776560519,
        'updatedAt' => 1776560524,
        'Guid' => [],
    ], $overrides);
}

/**
 * A single synthetic Plex episode Metadata line for the Specials-link case the
 * real `24` capture can't provide — only the stored keys the reconciler reads
 * plus the parent link. Keys are raw Plex wire keys.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function episodeMetadata(array $overrides = []): array
{
    return array_merge([
        'ratingKey' => '99001',
        'parentRatingKey' => '99000',
        'guid' => 'plex://episode/synthetic000000000000000',
        'type' => 'episode',
        'index' => 1,
        'parentIndex' => 0,
        'title' => 'Special 1',
        'addedAt' => 1776560519,
        'updatedAt' => 1776560524,
        'Guid' => [
            ['id' => 'tvdb://900001'],
        ],
    ], $overrides);
}

describe('handle() season upsert & prune', function (): void {
    it('upserts a season row mapping raw plex facts and the tvdb crosswalk', function (): void {
        // Arrange
        $show = PlexShow::factory()->create();
        $seasons = fixtureSeasonMetadata();

        // Act
        resolve(ReconcilePlexEpisodes::class)->handle($show, $seasons, []);

        // Assert
        $season = PlexSeason::query()
            ->where('plex_server_id', $show->plex_server_id)
            ->where('plex_show_id', $show->id)
            ->where('_plex_ratingKey', '34424')
            ->sole();
        expect($season->_plex_index)->toBe(2)
            ->and($season->_plex_title)->toBe('Season 2')
            ->and($season->_plex_leafCount)->toBe(24)
            ->and($season->_plex_guid)->toBe('plex://season/602e68f2d17ae1002dc13d5e')
            ->and($season->_tvdb_id)->toBe(10064);
        $this->assertDatabaseHas('plex_seasons', [
            '_plex_ratingKey' => '34424',
            '_plex_addedAt' => Date::createFromTimestamp(1776560519)->toDateTimeString(),
            '_plex_updatedAt' => Date::createFromTimestamp(1776560524)->toDateTimeString(),
        ]);
    });

    it('updates the season in place on a second run without duplicating', function (): void {
        // Arrange
        $show = PlexShow::factory()->create();
        $seasons = fixtureSeasonMetadata();

        // Act
        resolve(ReconcilePlexEpisodes::class)->handle($show, $seasons, []);
        resolve(ReconcilePlexEpisodes::class)->handle($show, $seasons, []);

        // Assert
        expect(PlexSeason::query()->where('_plex_ratingKey', '34424')->count())->toBe(1);
    });

    it('persists a Specials season at index 0', function (): void {
        // Arrange
        $show = PlexShow::factory()->create();
        $seasons = [...fixtureSeasonMetadata(), seasonMetadata([
            'ratingKey' => '99000',
            'guid' => 'plex://season/specials0000000000000000',
            'index' => 0,
            'title' => 'Specials',
        ])];

        // Act
        resolve(ReconcilePlexEpisodes::class)->handle($show, $seasons, []);

        // Assert
        $this->assertDatabaseHas('plex_seasons', [
            '_plex_ratingKey' => '99000',
            '_plex_index' => 0,
        ]);
    });

    it('excludes the index -1 all-episodes aggregate season', function (): void {
        // Arrange
        $show = PlexShow::factory()->create();
        $seasons = [...fixtureSeasonMetadata(), seasonMetadata([
            'ratingKey' => '99999',
            'guid' => 'plex://season/aggregate00000000000000',
            'index' => -1,
            'title' => 'All episodes',
        ])];

        // Act
        resolve(ReconcilePlexEpisodes::class)->handle($show, $seasons, []);

        // Assert
        $this->assertDatabaseHas('plex_seasons', ['_plex_ratingKey' => '34424']);
        $this->assertDatabaseMissing('plex_seasons', ['_plex_ratingKey' => '99999']);
    });

    it('prunes stale same-show seasons but leaves other shows untouched', function (): void {
        // Arrange
        $show = PlexShow::factory()->create();
        $stale = PlexSeason::factory()->create([
            'plex_server_id' => $show->plex_server_id,
            'plex_show_id' => $show->id,
            '_plex_ratingKey' => '70000',
        ]);
        $otherShowSeason = PlexSeason::factory()->create();

        // Act
        resolve(ReconcilePlexEpisodes::class)->handle($show, fixtureSeasonMetadata(), []);

        // Assert
        $this->assertDatabaseMissing('plex_seasons', ['id' => $stale->id]);
        $this->assertDatabaseHas('plex_seasons', ['id' => $otherShowSeason->id]);
    });

});

describe('handle() episode upsert & prune', function (): void {
    it('upserts episode rows mapping raw plex facts and the guid crosswalk', function (): void {
        // Arrange
        $show = PlexShow::factory()->create();
        $seasons = fixtureSeasonMetadata();
        $episodes = array_slice(fixtureEpisodeMetadata(), 0, 2);

        // Act
        resolve(ReconcilePlexEpisodes::class)->handle($show, $seasons, $episodes);

        // Assert
        $scoped = PlexEpisode::query()
            ->where('plex_server_id', $show->plex_server_id)
            ->where('plex_show_id', $show->id);
        expect($scoped->count())->toBe(2);
        $episode = (clone $scoped)->where('_plex_ratingKey', '34425')->sole();
        expect($episode->_plex_parentIndex)->toBe(2)
            ->and($episode->_plex_index)->toBe(1)
            ->and($episode->_plex_title)->toBe('Day 2: 8:00 A.M.-9:00 A.M.')
            ->and($episode->_plex_guid)->toBe('plex://episode/5d9c13507d06d9001fffb37d')
            ->and($episode->_plex_guids)->toBe([
                ['id' => 'imdb://tt0502205'],
                ['id' => 'tmdb://134418'],
                ['id' => 'tvdb://189279'],
            ])
            ->and($episode->_imdb_id)->toBe('tt0502205')
            ->and($episode->_tmdb_id)->toBe(134418)
            ->and($episode->_tvdb_id)->toBe(189279);
    });

    it('reports the number of episodes it reconciled for the show', function (): void {
        // Arrange
        $show = PlexShow::factory()->create();
        $seasons = fixtureSeasonMetadata();
        $episodes = fixtureEpisodeMetadata();

        // Act
        $total = resolve(ReconcilePlexEpisodes::class)->handle($show, $seasons, $episodes);

        // Assert
        expect($total)->toBe(24);
    });

    it('updates episodes in place on a second run without duplicating', function (): void {
        // Arrange
        $show = PlexShow::factory()->create();
        $seasons = fixtureSeasonMetadata();
        $episodes = array_slice(fixtureEpisodeMetadata(), 0, 2);

        // Act
        resolve(ReconcilePlexEpisodes::class)->handle($show, $seasons, $episodes);
        resolve(ReconcilePlexEpisodes::class)->handle($show, $seasons, $episodes);

        // Assert
        expect(PlexEpisode::query()->where('_plex_ratingKey', '34425')->count())->toBe(1)
            ->and(PlexEpisode::query()->count())->toBe(2);
    });

    it('prunes stale same-show episodes but leaves other shows untouched', function (): void {
        // Arrange
        $show = PlexShow::factory()->create();
        $season = PlexSeason::factory()->create([
            'plex_server_id' => $show->plex_server_id,
            'plex_show_id' => $show->id,
            '_plex_ratingKey' => '34424',
        ]);
        $stale = PlexEpisode::factory()->create([
            'plex_server_id' => $show->plex_server_id,
            'plex_show_id' => $show->id,
            'plex_season_id' => $season->id,
            '_plex_ratingKey' => '70000',
        ]);
        $otherShowEpisode = PlexEpisode::factory()->create();

        // Act
        resolve(ReconcilePlexEpisodes::class)->handle($show, fixtureSeasonMetadata(), array_slice(fixtureEpisodeMetadata(), 0, 2));

        // Assert
        $this->assertDatabaseMissing('plex_episodes', ['id' => $stale->id]);
        $this->assertDatabaseHas('plex_episodes', ['id' => $otherShowEpisode->id]);
    });
});

describe('episode → season link', function (): void {
    it('resolves each episode plex_season_id from its parentRatingKey season', function (): void {
        // Arrange
        $show = PlexShow::factory()->create();
        $seasons = fixtureSeasonMetadata();
        $episodes = array_slice(fixtureEpisodeMetadata(), 0, 2);

        // Act
        resolve(ReconcilePlexEpisodes::class)->handle($show, $seasons, $episodes);

        // Assert
        $seasonId = PlexSeason::query()
            ->where('plex_show_id', $show->id)
            ->where('_plex_ratingKey', '34424')
            ->value('id');
        $rows = PlexEpisode::query()->where('plex_show_id', $show->id)->get();
        expect($rows)->toHaveCount(2)
            ->and($rows->pluck('plex_season_id')->unique()->all())->toBe([$seasonId]);
    });

    it('links a Specials episode to its index 0 season', function (): void {
        // Arrange
        $show = PlexShow::factory()->create();
        $seasons = [...fixtureSeasonMetadata(), seasonMetadata([
            'ratingKey' => '99000',
            'guid' => 'plex://season/specials0000000000000000',
            'index' => 0,
            'title' => 'Specials',
        ])];
        $episodes = [episodeMetadata(['parentRatingKey' => '99000', 'parentIndex' => 0])];

        // Act
        resolve(ReconcilePlexEpisodes::class)->handle($show, $seasons, $episodes);

        // Assert
        $specials = PlexSeason::query()
            ->where('plex_show_id', $show->id)
            ->where('_plex_ratingKey', '99000')
            ->sole();
        $episode = PlexEpisode::query()->where('_plex_ratingKey', '99001')->sole();
        expect($episode->plex_season_id)->toBe($specials->id)
            ->and($specials->_plex_index)->toBe(0);
    });

    it('persists an orphan episode with a null plex_season_id', function (): void {
        // Arrange
        $show = PlexShow::factory()->create();
        $seasons = fixtureSeasonMetadata();
        $orphan = array_slice(fixtureEpisodeMetadata(), 0, 1);
        $orphan[0]['parentRatingKey'] = '88888';

        // Act
        resolve(ReconcilePlexEpisodes::class)->handle($show, $seasons, $orphan);

        // Assert
        $episode = PlexEpisode::query()->where('_plex_ratingKey', '34425')->sole();
        expect($episode->plex_season_id)->toBeNull();
    });
});

describe('malformed payload', function (): void {
    it('fails a season item missing the required title without writing a null', function (): void {
        // Arrange
        $show = PlexShow::factory()->create();
        $season = seasonMetadata();
        unset($season['title']);

        // Act
        $thrown = rescue(
            fn (): int => resolve(ReconcilePlexEpisodes::class)->handle($show, [$season], []),
            fn (Throwable $e): Throwable => $e,
            report: false,
        );

        // Assert
        expect($thrown)->toBeInstanceOf(Throwable::class)
            ->and($thrown)->not->toBeInstanceOf(QueryException::class);
        $this->assertDatabaseCount('plex_seasons', 0);
    });

    it('fails an episode item missing the required guid without writing a null', function (): void {
        // Arrange
        $show = PlexShow::factory()->create();
        $episode = episodeMetadata();
        unset($episode['guid']);

        // Act
        $thrown = rescue(
            fn (): int => resolve(ReconcilePlexEpisodes::class)->handle($show, fixtureSeasonMetadata(), [$episode]),
            fn (Throwable $e): Throwable => $e,
            report: false,
        );

        // Assert
        expect($thrown)->toBeInstanceOf(Throwable::class)
            ->and($thrown)->not->toBeInstanceOf(QueryException::class);
        $this->assertDatabaseCount('plex_episodes', 0);
    });
});
