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
| the native wire shape, NOT a hand-fabricated array. TVDB is the sole creator
| of `shows` rows: every payload upserts by `_tvdb_id`, seeding the _imdb_id /
| _tmdb_id crosswalks raw from remoteIds[] (the IMDB entry id "tt0903747", the
| TheMovieDB.com entry id "1396"). There is NO cross-source merge — _imdb_id is
| indexed, not unique, so two payloads sharing one imdb id yield two rows.
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
 * crosswalk- and dedupe-relevant values per test; the remaining keys are
 * harmless filler so the column mapper has every field it reads (keeping the
 * failure on the row-count/crosswalk assertion, not a missing-key crash).
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

it('seeds _imdb_id and _tmdb_id crosswalks from remoteIds on a fresh insert', function (): void {
    // Arrange
    $payloads = [tvdbSeries(['id' => 5551396, 'remoteIds' => [
        ['id' => 'tt0903747', 'type' => 2, 'sourceName' => 'IMDB'],
        ['id' => '1396', 'type' => 12, 'sourceName' => 'TheMovieDB.com'],
    ]])];

    // Act
    resolve(UpsertTvdbShows::class)->handle($payloads);

    // Assert
    $show = Show::query()->where('_tvdb_id', 5551396)->firstOrFail();
    expect($show->_imdb_id)->toBe('tt0903747')
        ->and($show->_tmdb_id)->toBe(1396);
});

it('drops an out-of-range TheMovieDB.com crosswalk to null, still importing the show', function (): void {
    // Arrange
    // 129536129536 overflows shows._tmdb_id (int unsigned, max 4,294,967,295) — a real TVDB garbage crosswalk.
    $payloads = [tvdbSeries(['id' => 8100001, 'remoteIds' => [
        ['id' => 'tt0903747', 'type' => 2, 'sourceName' => 'IMDB'],
        ['id' => '129536129536', 'type' => 12, 'sourceName' => 'TheMovieDB.com'],
    ]])];

    // Act
    resolve(UpsertTvdbShows::class)->handle($payloads);

    // Assert
    $show = Show::query()->where('_tvdb_id', 8100001)->firstOrFail();
    expect($show->_tvdb_id)->toBe(8100001)
        ->and($show->_tmdb_id)->toBeNull();
});

it('drops a leading-space garbage TheMovieDB.com crosswalk to null, still importing the show', function (): void {
    // Arrange
    // " 51996251996" is a real malformed TVDB crosswalk that overflows the int unsigned column.
    $payloads = [tvdbSeries(['id' => 8100002, 'remoteIds' => [
        ['id' => 'tt0903747', 'type' => 2, 'sourceName' => 'IMDB'],
        ['id' => ' 51996251996', 'type' => 12, 'sourceName' => 'TheMovieDB.com'],
    ]])];

    // Act
    resolve(UpsertTvdbShows::class)->handle($payloads);

    // Assert
    $show = Show::query()->where('_tvdb_id', 8100002)->firstOrFail();
    expect($show->_tvdb_id)->toBe(8100002)
        ->and($show->_tmdb_id)->toBeNull();
});

it('preserves an in-range TheMovieDB.com crosswalk, not over-nulling a legitimate id', function (): void {
    // Arrange
    $payloads = [tvdbSeries(['id' => 8100003, 'remoteIds' => [
        ['id' => 'tt0903747', 'type' => 2, 'sourceName' => 'IMDB'],
        ['id' => '1396', 'type' => 12, 'sourceName' => 'TheMovieDB.com'],
    ]])];

    // Act
    resolve(UpsertTvdbShows::class)->handle($payloads);

    // Assert
    expect(Show::query()->where('_tvdb_id', 8100003)->value('_tmdb_id'))->toBe(1396);
});

it('drops a slug-appended TheMovieDB.com crosswalk to null, still importing the show', function (): void {
    // Arrange
    // "1335814-silvio-santos" is a real slug-appended TVDB crosswalk; a raw (int) cast truncates it to 1335814.
    $payloads = [tvdbSeries(['id' => 8100010, 'remoteIds' => [
        ['id' => 'tt0903747', 'type' => 2, 'sourceName' => 'IMDB'],
        ['id' => '1335814-silvio-santos', 'type' => 12, 'sourceName' => 'TheMovieDB.com'],
    ]])];

    // Act
    resolve(UpsertTvdbShows::class)->handle($payloads);

    // Assert
    $show = Show::query()->where('_tvdb_id', 8100010)->firstOrFail();
    expect($show->_tvdb_id)->toBe(8100010)
        ->and($show->_tmdb_id)->toBeNull();
});

it('drops a malformed IMDb crosswalk to null, still importing the show', function (): void {
    // Arrange
    // "www.imdb.comtitlett1489340" is a real mangled TVDB IMDb crosswalk that isn't a valid tt-id.
    $payloads = [tvdbSeries(['id' => 8100011, 'remoteIds' => [
        ['id' => 'www.imdb.comtitlett1489340', 'type' => 2, 'sourceName' => 'IMDB'],
    ]])];

    // Act
    resolve(UpsertTvdbShows::class)->handle($payloads);

    // Assert
    $show = Show::query()->where('_tvdb_id', 8100011)->firstOrFail();
    expect($show->_tvdb_id)->toBe(8100011)
        ->and($show->_imdb_id)->toBeNull();
});

it('imports a show with a null crosswalk when remoteIds is a non-array scalar', function (): void {
    // Arrange
    // Malformed upstream: a non-array scalar remoteIds must not throw a TypeError.
    $payloads = [tvdbSeries(['id' => 8100020, 'remoteIds' => 'tt0903747'])];

    // Act
    resolve(UpsertTvdbShows::class)->handle($payloads);

    // Assert
    $show = Show::query()->where('_tvdb_id', 8100020)->firstOrFail();
    expect($show->_imdb_id)->toBeNull()
        ->and($show->_tmdb_id)->toBeNull();
});

it('drops a payload whose native id is malformed, writing no null-keyed row', function (): void {
    // Arrange
    // "129536129536-corrupt" is a malformed native id (oversized, slug-appended) — no
    // valid primary identity, so the row cannot be upserted by _tvdb_id.
    $payloads = [tvdbSeries(['id' => '129536129536-corrupt'])];

    // Act
    resolve(UpsertTvdbShows::class)->handle($payloads);

    // Assert
    expect(Show::query()->count())->toBe(0);
});

it('updates in place when the same _tvdb_id is re-run, leaving one row', function (): void {
    // Arrange
    $payloads = [tvdbSeries(['id' => 702])];
    resolve(UpsertTvdbShows::class)->handle($payloads);

    // Act
    resolve(UpsertTvdbShows::class)->handle($payloads);

    // Assert
    expect(Show::query()->where('_tvdb_id', 702)->count())->toBe(1);
});

it('persists both payloads sharing one TheMovieDB.com id, nulling the ambiguous _tmdb_id on both rows', function (): void {
    // Arrange
    $tmdb = ['id' => '7778001', 'type' => 12, 'sourceName' => 'TheMovieDB.com'];
    $a = tvdbSeries(['id' => 6663000, 'remoteIds' => [['id' => 'tt7778001', 'type' => 2, 'sourceName' => 'IMDB'], $tmdb]]);
    $b = tvdbSeries(['id' => 6664000, 'remoteIds' => [['id' => 'tt7778002', 'type' => 2, 'sourceName' => 'IMDB'], $tmdb]]);

    // Act
    resolve(UpsertTvdbShows::class)->handle([$a, $b]);

    // Assert
    expect(Show::query()->count())->toBe(2)
        ->and(Show::query()->where('_tvdb_id', 6663000)->value('_tmdb_id'))->toBeNull()
        ->and(Show::query()->where('_tvdb_id', 6664000)->value('_tmdb_id'))->toBeNull();
});

it('inserts two rows when two payloads share one imdb id', function (): void {
    // Arrange
    $imdb = [['id' => 'tt0903747', 'type' => 2, 'sourceName' => 'IMDB']];
    $first = tvdbSeries(['id' => 81189, 'remoteIds' => $imdb]);
    $second = tvdbSeries(['id' => 654321, 'name' => 'Second Show', 'remoteIds' => $imdb]);

    // Act
    resolve(UpsertTvdbShows::class)->handle([$first, $second]);

    // Assert
    expect(Show::query()->count())->toBe(2);
});

it('maps _tvdb_defaultSeasonType raw from the extended payload as an int', function (): void {
    // Arrange
    $series = json_decode(fixtureBytes('Catalog/tvdb/series_extended.json'), true)['data'];

    // Act
    resolve(UpsertTvdbShows::class)->handle([$series]);

    // Assert
    $show = Show::query()->where('_tvdb_id', 81189)->firstOrFail();
    expect($show->_tvdb_defaultSeasonType)->toBe(1);
});

it('leaves _tvdb_defaultSeasonType null when the payload omits it', function (): void {
    // Arrange
    $payloads = [tvdbSeries(['id' => 909090])];

    // Act
    resolve(UpsertTvdbShows::class)->handle($payloads);

    // Assert
    $show = Show::query()->where('_tvdb_id', 909090)->firstOrFail();
    expect($show->_tvdb_defaultSeasonType)->toBeNull();
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
