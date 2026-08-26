<?php

declare(strict_types=1);

use App\Domains\Catalog\Enums\ImdbDataset;
use App\Domains\Catalog\Models\Movie;
use App\Domains\Catalog\Models\Show;
use App\Domains\Catalog\Support\ImdbDatasetMarker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Fixture: tests/Fixtures/Catalog/imdb/title.basics.tsv.gz
|--------------------------------------------------------------------------
| Byte-exact real slice of the live IMDb title.basics dataset (.tsv.gz),
| header + 6 rows: tconst / titleType / primaryTitle / originalTitle /
| isAdult / startYear / endYear / runtimeMinutes / genres —
|   tt0000001  short     Carmencita       1894                 1    Documentary,Short
|   tt0064057  movie     Bacchanales 69   1969  isAdult=1      95   Adult
|   tt0133093  movie     The Matrix       1999                 136  Action,Sci-Fi
|   tt0137523  movie     Fight Club       1999                 139  Crime,Drama,Thriller
|   tt0816692  movie     Interstellar     2014                 169  Adventure,Drama,Sci-Fi
|   tt0903747  tvSeries  Breaking Bad     2008  endYear=2013   48   Crime,Drama,Thriller
|
| tt0064057 is the only adult row and tt0903747 the only row carrying an
| endYear; tt0000001 is left unseeded so an unmatched tconst is always in play.
*/

it('populates the basics columns on pre-seeded movies and shows', function (): void {
    // Arrange
    $matrix = Movie::factory()->create(['_imdb_id' => 'tt0133093']);
    $breakingBad = Show::factory()->create(['_imdb_id' => 'tt0903747']);
    Http::fake(['*datasets.imdbws.com*' => Http::response(fixtureBytes('Catalog/imdb/title.basics.tsv.gz'))]);

    // Act
    $this->artisan('catalog:sync-titles');

    // Assert
    $matrix->refresh();
    expect($matrix->_imdb_titleType)->toBe('movie')
        ->and($matrix->_imdb_primaryTitle)->toBe('The Matrix')
        ->and($matrix->_imdb_originalTitle)->toBe('The Matrix')
        ->and($matrix->_imdb_startYear)->toBe(1999)
        ->and($matrix->_imdb_endYear)->toBeNull()
        ->and($matrix->_imdb_runtimeMinutes)->toBe(136)
        ->and($matrix->_imdb_genres)->toBe(['Action', 'Sci-Fi']);

    $breakingBad->refresh();
    expect($breakingBad->_imdb_titleType)->toBe('tvSeries')
        ->and($breakingBad->_imdb_primaryTitle)->toBe('Breaking Bad')
        ->and($breakingBad->_imdb_startYear)->toBe(2008)
        ->and($breakingBad->_imdb_endYear)->toBe(2013)
        ->and($breakingBad->_imdb_runtimeMinutes)->toBe(48)
        ->and($breakingBad->_imdb_genres)->toBe(['Crime', 'Drama', 'Thriller']);
});

// The non-adult control title proves the run really streamed the fixture, so
// the adult row's null columns can only mean it was skipped — not that the
// command did nothing.
it('writes nothing for an adult title already in the catalog', function (): void {
    // Arrange
    $adult = Movie::factory()->create(['_imdb_id' => 'tt0064057']);
    $control = Movie::factory()->create(['_imdb_id' => 'tt0133093']);
    Http::fake(['*datasets.imdbws.com*' => Http::response(fixtureBytes('Catalog/imdb/title.basics.tsv.gz'))]);

    // Act
    $this->artisan('catalog:sync-titles');

    // Assert
    $adult->refresh();
    $control->refresh();
    expect($control->_imdb_primaryTitle)->toBe('The Matrix')
        ->and($adult->_imdb_titleType)->toBeNull()
        ->and($adult->_imdb_primaryTitle)->toBeNull()
        ->and($adult->_imdb_originalTitle)->toBeNull()
        ->and($adult->_imdb_startYear)->toBeNull()
        ->and($adult->_imdb_runtimeMinutes)->toBeNull()
        ->and($adult->_imdb_genres)->toBeNull();
});

it('reports how many adult rows it skipped', function (): void {
    // Arrange
    Movie::factory()->create(['_imdb_id' => 'tt0064057']);
    Http::fake(['*datasets.imdbws.com*' => Http::response(fixtureBytes('Catalog/imdb/title.basics.tsv.gz'))]);

    // Act & Assert
    $this->artisan('catalog:sync-titles')->expectsOutputToContain('1 adult');
});

// The adult tally is catalog-scoped, not dataset-scoped: the fixture's one
// adult row (tt0064057) is left unseeded, so a run that reports it would be
// counting rows it was never going to write. This pins the adult check as
// downstream of the catalog-membership decision, wherever that decision moves.
it('counts only catalog-matched adult rows', function (): void {
    // Arrange
    Movie::factory()->create(['_imdb_id' => 'tt0133093']);
    Http::fake(['*datasets.imdbws.com*' => Http::response(fixtureBytes('Catalog/imdb/title.basics.tsv.gz'))]);

    // Act & Assert
    $this->artisan('catalog:sync-titles')->expectsOutputToContain('0 adult');
});

// The dataset is millions of rows, so the run must never hold the catalog's
// whole _imdb_id column in memory: every id read is a bounded `in (…)` probe of
// the ids currently in hand. The non-empty check keeps the guard from passing
// for a run that read nothing at all.
it('never reads the catalog ids unbounded', function (): void {
    // Arrange
    Movie::factory()->create(['_imdb_id' => 'tt0133093']);
    Http::fake(['*datasets.imdbws.com*' => Http::response(fixtureBytes('Catalog/imdb/title.basics.tsv.gz'))]);
    $idColumnSelects = fn (): array => collect(DB::getQueryLog())
        ->pluck('query')
        ->map(fn (mixed $query): string => (string) $query)
        ->filter(fn (string $query): bool => Str::startsWith($query, 'select') && Str::contains($query, '_imdb_id'))
        ->values()
        ->all();
    DB::enableQueryLog();

    // Act
    $this->artisan('catalog:sync-titles');

    // Assert
    expect($idColumnSelects())->not->toBeEmpty();
    foreach ($idColumnSelects() as $query) {
        expect($query)->toContain('in (');
    }
});

// Asserting the fetch alongside the cleanup keeps "no leftover temp file" from
// passing for the wrong reason (a run that never downloaded anything).
it('downloads the basics dataset and deletes the temp file afterward', function (): void {
    // The stub handler hands us the request's $options, whose 'sink' is the exact
    // tempnam() path this run created — so the leak check pins that one path
    // instead of globbing the shared system temp dir, where a sibling test or a
    // parallel agent's files would flip the result for unrelated reasons.
    // Arrange
    $sinkPath = null;
    Http::fake(['*datasets.imdbws.com*' => function (Request $request, array $options) use (&$sinkPath) {
        $sinkPath = $options['sink'];

        return Http::response(fixtureBytes('Catalog/imdb/title.basics.tsv.gz'));
    }]);

    // Act
    $this->artisan('catalog:sync-titles');

    // Assert
    Http::assertSent(fn (Request $request): bool => Str::endsWith($request->url(), '/title.basics.tsv.gz'));
    expect($sinkPath)->toBeString();
    expect(file_exists($sinkPath))->toBeFalse();
});

// The beat counts dataset rows SCANNED, not titles written: at --batch=2 the
// pre-filter closes a probe batch after the fixture's 2nd, 4th and 6th row, so
// the two rows this run never writes — the unseeded tt0000001 and the adult
// tt0064057 — still show up in the count.
it('heartbeats cumulative scanned rows at each probe boundary', function (): void {
    // Arrange
    Movie::factory()->create(['_imdb_id' => 'tt0133093']);
    Movie::factory()->create(['_imdb_id' => 'tt0137523']);
    Movie::factory()->create(['_imdb_id' => 'tt0816692']);
    Show::factory()->create(['_imdb_id' => 'tt0903747']);
    Http::fake(['*datasets.imdbws.com*' => Http::response(fixtureBytes('Catalog/imdb/title.basics.tsv.gz'))]);

    // Act
    Artisan::call('catalog:sync-titles', ['--batch' => 2]);

    // Assert
    $output = Artisan::output();
    expect(substr_count($output, '[imdb titles'))->toBe(3)
        ->and($output)->toContain('[imdb titles 2]')
        ->and($output)->toContain('[imdb titles 4]')
        ->and($output)->toContain('[imdb titles 6]');
});

// A run that writes nothing is exactly the long catalog-miss stretch the beat
// exists for. The seeded tt9999999 is absent from the fixture, so its null
// basics columns prove the run wrote nothing — an empty-table count would pass
// for a run that never streamed a row.
it('keeps beating through a zero-match run', function (): void {
    // Arrange
    $unmatched = Movie::factory()->create(['_imdb_id' => 'tt9999999']);
    Http::fake(['*datasets.imdbws.com*' => Http::response(fixtureBytes('Catalog/imdb/title.basics.tsv.gz'))]);

    // Act
    Artisan::call('catalog:sync-titles', ['--batch' => 2]);

    // Assert
    expect(Artisan::output())
        ->toContain('[imdb titles 2]')
        ->toContain('[imdb titles 4]')
        ->toContain('[imdb titles 6]');
    expect($unmatched->refresh()->_imdb_titleType)->toBeNull()
        ->and($unmatched->_imdb_primaryTitle)->toBeNull()
        ->and($unmatched->_imdb_startYear)->toBeNull();
});

it('prints an elapsed phase line on completion', function (): void {
    // Shape only: the elapsed seconds are real wall clock around a streaming
    // read, so there is no value to freeze and assert.
    // Arrange
    Http::fake(['*datasets.imdbws.com*' => Http::response(fixtureBytes('Catalog/imdb/title.basics.tsv.gz'))]);

    // Act & Assert
    $this->artisan('catalog:sync-titles')->expectsOutputToContain('[elapsed');
});

/*
|--------------------------------------------------------------------------
| Last-Modified gate
|--------------------------------------------------------------------------
| The probe is a HEAD and the download a GET, both against the same dataset
| URL, so every fake below dispatches on $request->method(): the HEAD arm
| carries the header under test, the GET arm serves the real fixture bytes.
*/

it('skips the basics download when the dataset is unchanged', function (): void {
    // Arrange
    $header = 'Tue, 12 Aug 2026 01:02:03 GMT';
    resolve(ImdbDatasetMarker::class)->advance(ImdbDataset::TitleBasics, $header);
    $matrix = Movie::factory()->create(['_imdb_id' => 'tt0133093']);
    Http::fake(fn (Request $request) => $request->method() === 'HEAD'
        ? Http::response('', 200, ['Last-Modified' => $header])
        : Http::response(fixtureBytes('Catalog/imdb/title.basics.tsv.gz')));

    // Act
    $exitCode = Artisan::call('catalog:sync-titles');

    // Assert
    expect(Artisan::output())->toContain('unchanged');
    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'GET');
    expect($matrix->refresh()->_imdb_primaryTitle)->toBeNull();
    expect($exitCode)->toBe(0);
});

it('advances the basics marker after a successful sync', function (): void {
    // Arrange
    $header = 'Wed, 13 Aug 2026 04:05:06 GMT';
    $matrix = Movie::factory()->create(['_imdb_id' => 'tt0133093']);
    Http::fake(fn (Request $request) => $request->method() === 'HEAD'
        ? Http::response('', 200, ['Last-Modified' => $header])
        : Http::response(fixtureBytes('Catalog/imdb/title.basics.tsv.gz')));

    // Act
    Artisan::call('catalog:sync-titles');

    // Assert
    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET' && Str::endsWith($request->url(), '/title.basics.tsv.gz'));
    expect($matrix->refresh()->_imdb_primaryTitle)->toBe('The Matrix');
    expect(resolve(ImdbDatasetMarker::class)->current(ImdbDataset::TitleBasics))->toBe($header);
});
