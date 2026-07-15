<?php

declare(strict_types=1);

use App\Domains\Catalog\Actions\UpsertTmdbShows;
use App\Domains\Catalog\Actions\UpsertTvdbShows;
use App\Domains\Catalog\Models\Show;

/*
|--------------------------------------------------------------------------
| End-to-end characterization of FLIX-210: a TVDB show and a TMDB show for
| the SAME title collapse into ONE Show row in either ingest order, even
| when they share NO imdb id and the second payload does not re-carry the
| first source's id. The TMDB input is the byte-exact real fixture
| tests/Fixtures/Catalog/tmdb/tv.json ("Game of Thrones", id 1399); the
| TVDB input mirrors the field set UpsertTvdbShows reads (shape copied from
| the tvdbSeries() helper in UpsertTvdbShowsTest.php, redeclared locally
| under a distinct name to avoid a Pest cross-file function collision).
|
| The merge is only possible because each source's insert SEEDS the cross
| id it extracts (Slices 1/3): UpsertTmdbShows seeds _tvdb_id from
| external_ids.tvdb_id, UpsertTvdbShows seeds _tmdb_id from the
| remoteIds[TheMovieDB.com] entry. ExistingShowResolver then matches the
| later source on that seeded id — the ONLY shared key in each test.
|--------------------------------------------------------------------------
*/

/**
 * Minimal-but-complete TVDB series carrying every key the column mapper
 * reads, so a test's failure lands on the merge assertion rather than a
 * missing-key crash. Only id / remoteIds vary per test.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function crossSourceTvdbSeries(array $overrides = []): array
{
    return array_merge([
        'id' => 8880001,
        'name' => 'Crossover Show',
        'slug' => 'crossover-show',
        'overview' => '',
        'score' => 100,
        'firstAired' => '2008-01-20',
        'lastAired' => '2013-09-29',
        'year' => '2008',
        'averageRuntime' => 48,
        'status' => ['id' => 2, 'name' => 'Ended', 'recordType' => 'series', 'keepUpdated' => false],
        'originalLanguage' => 'eng',
        'originalCountry' => 'usa',
        'genres' => [['id' => 12, 'name' => 'Drama', 'slug' => 'drama']],
        'remoteIds' => [],
    ], $overrides);
}

it('merges a TVDB show onto a TMDB-first row sharing only the tvdb id into one row', function (): void {
    // Arrange
    $tmdb = json_decode(fixtureBytes('Catalog/tmdb/tv.json'), true);
    $tmdb['id'] = 5559001;
    $tmdb['external_ids']['tvdb_id'] = 8880001;
    unset($tmdb['external_ids']['imdb_id']);
    $tvdb = crossSourceTvdbSeries(['id' => 8880001, 'remoteIds' => []]);

    // Act
    resolve(UpsertTmdbShows::class)->handle([$tmdb]);
    resolve(UpsertTvdbShows::class)->handle([$tvdb]);

    // Assert
    $fresh = Show::query()->where('_tvdb_id', 8880001)->firstOrFail();
    expect(Show::query()->count())->toBe(1)
        ->and($fresh->_tmdb_id)->toBe(5559001)
        ->and($fresh->_tvdb_name)->toBe('Crossover Show');
});

it('merges a TMDB show onto a TVDB-first row sharing only the tmdb id into one row', function (): void {
    // Arrange
    $tvdb = crossSourceTvdbSeries(['id' => 8880002, 'remoteIds' => [
        ['id' => '5559002', 'type' => 12, 'sourceName' => 'TheMovieDB.com'],
    ]]);
    $tmdb = json_decode(fixtureBytes('Catalog/tmdb/tv.json'), true);
    $tmdb['id'] = 5559002;
    unset($tmdb['external_ids']['imdb_id'], $tmdb['external_ids']['tvdb_id']);

    // Act
    resolve(UpsertTvdbShows::class)->handle([$tvdb]);
    resolve(UpsertTmdbShows::class)->handle([$tmdb]);

    // Assert
    $fresh = Show::query()->where('_tmdb_id', 5559002)->firstOrFail();
    expect(Show::query()->count())->toBe(1)
        ->and($fresh->_tvdb_id)->toBe(8880002)
        ->and($fresh->_tmdb_name)->toBe('Game of Thrones');
});
