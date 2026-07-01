<?php

declare(strict_types=1);

use App\Domains\Catalog\Actions\UpsertTmdbShows;
use App\Domains\Catalog\Actions\UpsertTvdbShows;
use App\Domains\Catalog\Models\Show;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Input payloads are decoded TheTVDB /series/{id}/extended responses, loaded
| byte-exact from the committed fixture
| tests/Fixtures/Catalog/tvdb/series_extended.json (a real TVDB API response
| for "Breaking Bad", id 81189) — the action consumes the inner `data` object,
| the native wire shape, NOT a hand-fabricated array. Unlike TMDB, the IMDb id
| is NOT a top-level key: it lives in remoteIds[] as the entry whose
| sourceName == "IMDB" (id "tt0903747"). The tvdb-only dedupe key is _tvdb_id.
|--------------------------------------------------------------------------
*/

it('maps the extended series to _tvdb_* columns, stamps tvdb_synced_at, and returns 1', function (): void {
    // Arrange
    $series = json_decode(fixtureBytes('Catalog/tvdb/series_extended.json'), true)['data'];

    // Act
    $count = resolve(UpsertTvdbShows::class)->handle([$series]);

    // Assert
    expect($count)->toBe(1);
    $show = Show::query()->where('_tvdb_id', 81189)->firstOrFail();
    expect($show->_tvdb_id)->toBe(81189)
        ->and($show->_tvdb_name)->toBe('Breaking Bad')
        ->and($show->_tvdb_year)->toBe(2008)
        ->and($show->_tvdb_averageRuntime)->toBe(48)
        ->and($show->_tvdb_firstAired->format('Y-m-d'))->toBe('2008-01-20')
        ->and($show->tvdb_synced_at)->not->toBeNull();
});

it('stores _tvdb_remoteIds and _tvdb_genres raw, byte-for-byte the source json', function (): void {
    // Arrange
    $series = json_decode(fixtureBytes('Catalog/tvdb/series_extended.json'), true)['data'];

    // Act
    resolve(UpsertTvdbShows::class)->handle([$series]);

    // Assert
    $remoteIds = DB::table('shows')->where('_tvdb_id', 81189)->value('_tvdb_remoteIds');
    $genres = DB::table('shows')->where('_tvdb_id', 81189)->value('_tvdb_genres');
    expect($remoteIds)->toBe(json_encode($series['remoteIds']))
        ->and($genres)->toBe(json_encode($series['genres']));
});

/**
 * Build a minimal-but-complete TVDB series: only id / remoteIds carry the
 * dedupe- and merge-relevant values per test; the remaining keys are harmless
 * filler so the column mapper has every field it reads (keeping the failure on
 * the dedupe/merge assertion, not a missing-key crash).
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function tvdbSeries(array $overrides = []): array
{
    return array_merge([
        'id' => 81189,
        'name' => 'Breaking Bad',
        'slug' => 'breaking-bad',
        'overview' => '',
        'score' => 3781028,
        'firstAired' => '2008-01-20',
        'lastAired' => '2013-09-29',
        'year' => '2008',
        'averageRuntime' => 48,
        'status' => ['id' => 2, 'name' => 'Ended', 'recordType' => 'series', 'keepUpdated' => false],
        'originalLanguage' => 'eng',
        'originalCountry' => 'usa',
        'genres' => [['id' => 12, 'name' => 'Drama', 'slug' => 'drama']],
        'remoteIds' => [['id' => 'tt0903747', 'type' => 2, 'sourceName' => 'IMDB']],
    ], $overrides);
}

it('merges onto an existing imdb show, pulling imdb_id from the remoteIds IMDB entry, without clobbering imdb columns', function (): void {
    // Arrange
    $existing = Show::factory()->create(['_imdb_id' => 'tt0903747']);
    $originalTitle = $existing->_imdb_primary_title;
    $originalGenres = $existing->_imdb_genres->all();
    $originalVotes = $existing->_imdb_num_votes;

    // Act
    resolve(UpsertTvdbShows::class)->handle([
        tvdbSeries(['id' => 81189, 'remoteIds' => [['id' => 'tt0903747', 'type' => 2, 'sourceName' => 'IMDB']]]),
    ]);

    // Assert
    $fresh = Show::query()->where('_imdb_id', 'tt0903747')->firstOrFail();
    expect(Show::query()->count())->toBe(1)
        ->and($fresh->_tvdb_id)->toBe(81189)
        ->and($fresh->_imdb_primary_title)->toBe($originalTitle)
        ->and($fresh->_imdb_genres->all())->toEqual($originalGenres)
        ->and($fresh->_imdb_num_votes)->toBe($originalVotes);
});

it('coalesces sequential tvdb-then-tmdb upserts onto one imdb-anchored row carrying both source blocks', function (): void {
    // Arrange
    Show::factory()->create(['_imdb_id' => 'tt0903747']);
    $tmdbPayload = json_decode(fixtureBytes('Catalog/tmdb/tv.json'), true);
    $tmdbPayload['external_ids']['imdb_id'] = 'tt0903747';

    // Act
    resolve(UpsertTvdbShows::class)->handle([tvdbSeries(['id' => 81189])]);
    resolve(UpsertTmdbShows::class)->handle([$tmdbPayload]);

    // Assert
    $fresh = Show::query()->where('_imdb_id', 'tt0903747')->firstOrFail();
    expect(Show::query()->count())->toBe(1)
        ->and($fresh->_tvdb_id)->toBe(81189)
        ->and($fresh->_tvdb_name)->toBe('Breaking Bad')
        ->and($fresh->_tmdb_id)->toBe(1399)
        ->and($fresh->_tmdb_name)->toBe('Game of Thrones');
});

it('merges the extended series onto an existing tmdb-only row via the TheMovieDB.com remoteId when imdb matches nothing', function (): void {
    // Arrange
    $series = json_decode(fixtureBytes('Catalog/tvdb/series_extended.json'), true)['data'];
    $existing = Show::factory()->withTmdb()->create(['_imdb_id' => null, '_tmdb_id' => 1396]);
    $originalTmdbName = $existing->_tmdb_name;

    // Act
    resolve(UpsertTvdbShows::class)->handle([$series]);

    // Assert
    $fresh = Show::query()->where('_tmdb_id', 1396)->firstOrFail();
    expect(Show::query()->count())->toBe(1)
        ->and($fresh->_tvdb_id)->toBe(81189)
        ->and($fresh->_tmdb_id)->toBe(1396)
        ->and($fresh->_tmdb_name)->toBe($originalTmdbName);
});

it('inserts a tvdb-only show with null imdb_id when no existing imdb show matches the remoteIds IMDB entry', function (): void {
    // Arrange
    $payloads = [tvdbSeries(['id' => 700, 'remoteIds' => [['id' => 'tt9999999', 'type' => 2, 'sourceName' => 'IMDB']]])];

    // Act
    resolve(UpsertTvdbShows::class)->handle($payloads);

    // Assert
    $show = Show::query()->where('_tvdb_id', 700)->firstOrFail();
    expect($show->_imdb_id)->toBeNull()
        ->and($show->_tvdb_id)->toBe(700);
});

it('does not duplicate a tvdb-only show when the same payload is re-run', function (): void {
    // Arrange
    $payloads = [tvdbSeries(['id' => 702])];
    resolve(UpsertTvdbShows::class)->handle($payloads);

    // Act
    resolve(UpsertTvdbShows::class)->handle($payloads);

    // Assert
    expect(Show::query()->where('_tvdb_id', 702)->count())->toBe(1);
});

it('writes one last-wins row when two payloads in one batch share an imdb_id', function (): void {
    // Arrange
    $imdb = [['id' => 'tt0903747', 'type' => 2, 'sourceName' => 'IMDB']];
    $first = tvdbSeries(['id' => 81189, 'remoteIds' => $imdb]);
    $last = tvdbSeries(['id' => 654321, 'name' => 'Winning Write', 'remoteIds' => $imdb]);

    // Act
    resolve(UpsertTvdbShows::class)->handle([$first, $last]);

    // Assert
    $fresh = Show::query()->where('_tvdb_id', 654321)->firstOrFail();
    expect(Show::query()->count())->toBe(1)
        ->and($fresh->_tvdb_id)->toBe(654321)
        ->and($fresh->_tvdb_name)->toBe('Winning Write');
});

it('returns 0 and persists nothing for empty input', function (): void {
    // Arrange
    $payloads = [];

    // Act
    $count = resolve(UpsertTvdbShows::class)->handle($payloads);

    // Assert
    expect($count)->toBe(0)
        ->and(Show::query()->count())->toBe(0);
});
