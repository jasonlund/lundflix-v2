<?php

declare(strict_types=1);

use App\Domains\Catalog\Actions\UpsertShows;
use App\Domains\Catalog\Actions\UpsertTmdbShows;
use App\Domains\Catalog\Actions\UpsertTvdbShows;
use App\Domains\Catalog\Models\Show;

/*
|--------------------------------------------------------------------------
| Cross-source CONVERGENCE characterization: drive the three real ingest
| actions end-to-end (UpsertShows = IMDb TSV, UpsertTmdbShows = decoded
| /tv, UpsertTvdbShows = decoded /series/extended.data) and assert that,
| however the same title arrives, the catalog converges on ONE Show row
| carrying all present source ids. Every payload below cross-references a
| single linked identity — imdb tt0903747 <-> tmdb 1399 <-> tvdb 81189 —
| built from the byte-exact fixtures tests/Fixtures/Catalog/tmdb/tv.json
| and tests/Fixtures/Catalog/tvdb/series_extended.json with only the
| three cross-ref ids overridden to that identity.
|
| SyncCatalog runs a FIXED order (imdb -> tmdb movies -> tmdb shows ->
| tvdb shows), so IMDb is always ingested first and TVDB after TMDB; the
| production-order tests follow that sequence. Under that sole-writer
| order each later source single-matches the row an earlier source made,
| so these lock in cross-source single-match convergence and are EXPECTED
| to pass on arrival.
|--------------------------------------------------------------------------
*/

/**
 * One IMDb TSV-shaped row for the linked identity (tconst tt0903747),
 * shaped exactly like ImdbDatasetService::rows() output.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function convergenceImdbRow(array $overrides = []): array
{
    return array_merge([
        'tconst' => 'tt0903747',
        'titleType' => 'tvSeries',
        'primaryTitle' => 'Breaking Bad',
        'originalTitle' => 'Breaking Bad',
        'startYear' => 2008,
        'endYear' => 2013,
        'runtimeMinutes' => 49,
        'genres' => ['Crime', 'Drama', 'Thriller'],
    ], $overrides);
}

/**
 * Real TMDB /tv payload re-pointed at the linked identity: tmdb id 1399,
 * external imdb tt0903747 and tvdb 81189. Pass overrides to unset/replace
 * cross-ref ids for the no-imdb variants.
 *
 * @return array<string, mixed>
 */
function convergenceTmdbPayload(): array
{
    $payload = json_decode(fixtureBytes('Catalog/tmdb/tv.json'), true);
    $payload['id'] = 1399;
    $payload['external_ids']['imdb_id'] = 'tt0903747';
    $payload['external_ids']['tvdb_id'] = 81189;

    return $payload;
}

/**
 * Real TVDB /series/extended data re-pointed at the linked identity: id
 * 81189, remoteIds carrying imdb tt0903747 and TheMovieDB.com 1399.
 *
 * @return array<string, mixed>
 */
function convergenceTvdbSeries(): array
{
    $series = json_decode(fixtureBytes('Catalog/tvdb/series_extended.json'), true)['data'];
    $series['id'] = 81189;
    $series['remoteIds'] = [
        ['id' => 'tt0903747', 'type' => 2, 'sourceName' => 'IMDB'],
        ['id' => '1399', 'type' => 12, 'sourceName' => 'TheMovieDB.com'],
    ];

    return $series;
}

it('converges imdb-anchored ingest in production order onto one fully-linked row', function (): void {
    // Arrange
    $imdbRow = convergenceImdbRow();
    $tmdbPayload = convergenceTmdbPayload();
    $tvdbSeries = convergenceTvdbSeries();

    // Act
    resolve(UpsertShows::class)->handle([$imdbRow]);
    resolve(UpsertTmdbShows::class)->handle([$tmdbPayload]);
    resolve(UpsertTvdbShows::class)->handle([$tvdbSeries]);

    // Assert
    $show = Show::query()->where('_imdb_id', 'tt0903747')->firstOrFail();
    expect(Show::query()->count())->toBe(1)
        ->and($show->_imdb_id)->toBe('tt0903747')
        ->and($show->_tmdb_id)->toBe(1399)
        ->and($show->_tvdb_id)->toBe(81189);
});

it('converges a no-imdb tmdb-then-tvdb sequence onto one tmdb+tvdb row with null imdb', function (): void {
    // Arrange
    $tmdbPayload = convergenceTmdbPayload();
    unset($tmdbPayload['external_ids']['imdb_id']);
    $tvdbSeries = convergenceTvdbSeries();
    $tvdbSeries['remoteIds'] = [['id' => '1399', 'type' => 12, 'sourceName' => 'TheMovieDB.com']];

    // Act
    resolve(UpsertTmdbShows::class)->handle([$tmdbPayload]);
    resolve(UpsertTvdbShows::class)->handle([$tvdbSeries]);

    // Assert
    $show = Show::query()->where('_tmdb_id', 1399)->firstOrFail();
    expect(Show::query()->count())->toBe(1)
        ->and($show->_tmdb_id)->toBe(1399)
        ->and($show->_tvdb_id)->toBe(81189)
        ->and($show->_imdb_id)->toBeNull();
});

it('is idempotent — a second identical sync neither re-splits nor re-creates the row', function (): void {
    // Arrange
    resolve(UpsertShows::class)->handle([convergenceImdbRow()]);
    resolve(UpsertTmdbShows::class)->handle([convergenceTmdbPayload()]);
    resolve(UpsertTvdbShows::class)->handle([convergenceTvdbSeries()]);
    $firstRunId = Show::query()->where('_imdb_id', 'tt0903747')->firstOrFail()->id;

    // Act
    resolve(UpsertShows::class)->handle([convergenceImdbRow()]);
    resolve(UpsertTmdbShows::class)->handle([convergenceTmdbPayload()]);
    resolve(UpsertTvdbShows::class)->handle([convergenceTvdbSeries()]);

    // Assert
    $survivor = Show::query()->where('_imdb_id', 'tt0903747')->firstOrFail();
    expect(Show::query()->count())->toBe(1)
        ->and($survivor->id)->toBe($firstRunId)
        ->and($survivor->_imdb_id)->toBe('tt0903747')
        ->and($survivor->_tmdb_id)->toBe(1399)
        ->and($survivor->_tvdb_id)->toBe(81189);
});
