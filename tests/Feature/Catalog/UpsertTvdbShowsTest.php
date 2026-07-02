<?php

declare(strict_types=1);

use App\Domains\Catalog\Actions\UpsertTvdbShows;
use App\Domains\Catalog\Models\Show;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Input payloads are decoded TheTVDB /series/{id}/extended responses, loaded
| byte-exact from the committed fixture
| tests/Fixtures/Catalog/tvdb/series_extended.json (a real TVDB API response
| for "Breaking Bad", id 81189) — the action consumes the inner `data` object,
| the native wire shape, NOT a hand-fabricated array. TVDB is the source of
| truth for Shows: handle() is a pure upsert keyed on _tvdb_id. The IMDb id is
| NOT a top-level key — it lives in remoteIds[] as the entry whose
| sourceName == "IMDB" (id "tt0903747") and is written raw into _imdb_id.
|--------------------------------------------------------------------------
*/

/**
 * Build a minimal-but-complete TVDB series: only id / name / remoteIds carry the
 * dedupe- and mapping-relevant values per test; the remaining keys are harmless
 * filler so the column mapper has every field it reads (keeping the failure on
 * the assertion under test, not a missing-key crash). All keys are raw TVDB
 * payload keys the mapper reads.
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

it('creates a show keyed by _tvdb_id and sets _imdb_id from the remoteIds IMDB entry', function (): void {
    // Arrange
    $payloads = [tvdbSeries(['id' => 81189, 'remoteIds' => [['id' => 'tt0903747', 'type' => 2, 'sourceName' => 'IMDB']]])];

    // Act
    $count = resolve(UpsertTvdbShows::class)->handle($payloads);

    // Assert
    expect($count)->toBe(1);
    $show = Show::query()->where('_tvdb_id', 81189)->firstOrFail();
    expect($show->_tvdb_id)->toBe(81189)
        ->and($show->_imdb_id)->toBe('tt0903747');
});

it('leaves _imdb_id null when no remoteIds entry has sourceName IMDB', function (): void {
    // Arrange
    $payloads = [tvdbSeries(['id' => 700, 'remoteIds' => [['id' => '1396', 'type' => 12, 'sourceName' => 'TheMovieDB.com']]])];

    // Act
    resolve(UpsertTvdbShows::class)->handle($payloads);

    // Assert
    $show = Show::query()->where('_tvdb_id', 700)->firstOrFail();
    expect($show->_imdb_id)->toBeNull();
});

it('updates in place when the same _tvdb_id is re-handled', function (): void {
    // Arrange
    resolve(UpsertTvdbShows::class)->handle([tvdbSeries(['id' => 702, 'name' => 'Breaking Bad'])]);

    // Act
    resolve(UpsertTvdbShows::class)->handle([tvdbSeries(['id' => 702, 'name' => 'Breaking Bad Reloaded'])]);

    // Assert
    expect(Show::query()->count())->toBe(1);
    $show = Show::query()->where('_tvdb_id', 702)->firstOrFail();
    expect($show->_tvdb_name)->toBe('Breaking Bad Reloaded');
});

it('maps _tvdb_* raw and stamps tvdb_synced_at from the extended fixture', function (): void {
    // Arrange
    $series = json_decode(fixtureBytes('Catalog/tvdb/series_extended.json'), true)['data'];

    // Act
    resolve(UpsertTvdbShows::class)->handle([$series]);

    // Assert
    $show = Show::query()->where('_tvdb_id', 81189)->firstOrFail();
    expect($show->_tvdb_name)->toBe('Breaking Bad')
        ->and($show->_tvdb_year)->toBe(2008)
        ->and($show->_tvdb_firstAired->format('Y-m-d'))->toBe('2008-01-20')
        ->and($show->tvdb_synced_at)->not->toBeNull()
        ->and(DB::table('shows')->where('_tvdb_id', 81189)->value('_tvdb_remoteIds'))
        ->toBe(json_encode($series['remoteIds']));
});

it('writes one last-wins row when two payloads in one batch share an imdb id', function (): void {
    // Arrange
    $imdb = [['id' => 'tt0903747', 'type' => 2, 'sourceName' => 'IMDB']];
    $first = tvdbSeries(['id' => 81189, 'remoteIds' => $imdb]);
    $last = tvdbSeries(['id' => 654321, 'name' => 'Winning Write', 'remoteIds' => $imdb]);

    // Act
    resolve(UpsertTvdbShows::class)->handle([$first, $last]);

    // Assert
    expect(Show::query()->count())->toBe(1);
    $show = Show::query()->where('_tvdb_id', 654321)->firstOrFail();
    expect($show->_tvdb_id)->toBe(654321)
        ->and($show->_tvdb_name)->toBe('Winning Write');
});
