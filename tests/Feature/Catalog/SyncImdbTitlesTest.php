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

describe('catalog:sync-titles basics ingest and adult flagging', function (): void {
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

    // A refused title is stored and filtered at read, never skipped (ADR-0004), so
    // the adult row is ingested like any other. The non-adult control title keeps
    // the assertion honest: without it, a run that streamed nothing could not be
    // told apart from one that wrote the adult row.
    it('writes the basics columns and the adult flag for an adult title', function (): void {
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
            ->and($adult->_imdb_titleType)->toBe('movie')
            ->and($adult->_imdb_primaryTitle)->toBe('Bacchanales 69')
            ->and($adult->_imdb_originalTitle)->toBe('Bacchanales 69')
            ->and($adult->_imdb_startYear)->toBe(1969)
            ->and($adult->_imdb_endYear)->toBeNull()
            ->and($adult->_imdb_runtimeMinutes)->toBe(95)
            ->and($adult->_imdb_genres)->toBe(['Adult'])
            ->and($adult->_imdb_isAdult)->toBeTrue();
    });

    // Storing the row only pays off if it then reads as refused, so the ingested
    // flag has to reach both the model check and the query scope every read path
    // filters on.
    it('records an adult title as refused', function (): void {
        // Arrange
        $adult = Movie::factory()->create(['_imdb_id' => 'tt0064057']);
        Http::fake(['*datasets.imdbws.com*' => Http::response(fixtureBytes('Catalog/imdb/title.basics.tsv.gz'))]);

        // Act
        $this->artisan('catalog:sync-titles');

        // Assert
        expect($adult->refresh()->isRefused())->toBeTrue()
            ->and(Movie::query()->notRefused()->pluck('_imdb_id')->all())->not->toContain('tt0064057');
    });

    // The dataset supplies isAdult on every row, so an ordinary title has to come
    // out false, not null: null means unknown and would let an ingested title sit
    // in the catalog with its refusal status never actually established.
    it('writes a false adult flag for an ordinary title', function (): void {
        // Arrange
        $matrix = Movie::factory()->create(['_imdb_id' => 'tt0133093']);
        Http::fake(['*datasets.imdbws.com*' => Http::response(fixtureBytes('Catalog/imdb/title.basics.tsv.gz'))]);

        // Act
        $this->artisan('catalog:sync-titles');

        // Assert
        expect($matrix->refresh()->_imdb_isAdult)->toBeFalse();
    });

    // The run withholds nothing now, so a closing skip tally would be accounting
    // for a decision it no longer makes. The Done. line keeps the absence check
    // from passing for a run that printed nothing at all.
    it('closes the run without an adult skip tally', function (): void {
        // Arrange
        Movie::factory()->create(['_imdb_id' => 'tt0064057']);
        Http::fake(['*datasets.imdbws.com*' => Http::response(fixtureBytes('Catalog/imdb/title.basics.tsv.gz'))]);

        // Act
        Artisan::call('catalog:sync-titles');

        // Assert
        expect(Artisan::output())->toContain('Done.')
            ->not->toContain('adult');
    });
});

describe('catalog:sync-titles streaming reads and fetch', function (): void {
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
});

describe('catalog:sync-titles heartbeat output', function (): void {
    // The beat counts dataset rows SCANNED, not titles written: at --batch=2 the
    // pre-filter closes a probe batch after the fixture's 2nd, 4th and 6th row, so
    // the two rows this run never writes — tt0000001 and tt0064057, both left out
    // of the catalog above — still show up in the count.
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
});

describe('catalog:sync-titles marker gating', function (): void {
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
});

describe('catalog:sync-titles --batch ceiling', function (): void {
    // Titles is the widest IMDb write, so it is the leg whose ceiling the MySQL
    // placeholder cap actually binds — see the cap arithmetic pinned below.
    it('reduces a --batch above the titles ceiling', function (): void {
        // Arrange
        Http::fake(['*datasets.imdbws.com*' => Http::response(fixtureBytes('Catalog/imdb/title.basics.tsv.gz'))]);

        // Act
        Artisan::call('catalog:sync-titles', ['--batch' => 10000]);

        // Assert
        preg_match('/Reducing --batch 10000 to the titles ceiling of (\d+)\./', Artisan::output(), $announced);
        expect($announced)->not->toBeEmpty();
        expect((int) $announced[1])->toBeLessThanOrEqual(3854);
    });

    // The fence against over-correcting: 2000 is the leg's own default batch, so a
    // ceiling that clamps it would be reducing ordinary use.
    it('leaves a --batch below the titles ceiling alone', function (): void {
        // Arrange
        Movie::factory()->create(['_imdb_id' => 'tt0133093']);
        Http::fake(['*datasets.imdbws.com*' => Http::response(fixtureBytes('Catalog/imdb/title.basics.tsv.gz'))]);

        // Act
        Artisan::call('catalog:sync-titles', ['--batch' => 2000]);

        // Assert
        expect(Artisan::output())->not->toContain('Reducing --batch');
    });

    // What makes the number above non-arbitrary. MySQL caps one prepared statement
    // at 65,535 placeholders, and a fully-matched batch spends the whole ceiling in
    // one bulk CASE update. The per-row binding cost is MEASURED off the real
    // update's binding list rather than hardcoded, so a ninth written column fails
    // this test instead of quietly raising the cost past a frozen constant.
    it('keeps the titles ceiling under the bulk-update placeholder cap', function (): void {
        // Arrange
        $mysqlPlaceholderCap = 65535;
        $matchedTitles = 4;
        Movie::factory()->create(['_imdb_id' => 'tt0133093']);
        Movie::factory()->create(['_imdb_id' => 'tt0137523']);
        Movie::factory()->create(['_imdb_id' => 'tt0816692']);
        Movie::factory()->create(['_imdb_id' => 'tt0064057']);
        Http::fake(['*datasets.imdbws.com*' => Http::response(fixtureBytes('Catalog/imdb/title.basics.tsv.gz'))]);
        DB::enableQueryLog();

        // Act
        Artisan::call('catalog:sync-titles', ['--batch' => 10000]);

        // Assert
        preg_match('/Reducing --batch 10000 to the titles ceiling of (\d+)\./', Artisan::output(), $announced);
        expect($announced)->not->toBeEmpty();

        // One update statement is issued per table with matches; only movies match here.
        $bulkUpdate = collect(DB::getQueryLog())
            ->first(fn (array $logged): bool => Str::startsWith((string) $logged['query'], 'update') && Str::contains((string) $logged['query'], 'movies'));
        expect($bulkUpdate)->not->toBeNull();

        // The lone non-per-row binding is the uniform updated_at stamp.
        $bindingsPerRow = intdiv(count($bulkUpdate['bindings']) - 1, $matchedTitles);
        expect((int) $announced[1] * $bindingsPerRow + 1)->toBeLessThanOrEqual($mysqlPlaceholderCap);
    });
});

describe('catalog:sync-titles run-closing output', function (): void {
    it('ends a completed run with a Done. line', function (): void {
        // Arrange
        Movie::factory()->create(['_imdb_id' => 'tt0133093']);
        Http::fake(['*datasets.imdbws.com*' => Http::response(fixtureBytes('Catalog/imdb/title.basics.tsv.gz'))]);

        // Act & Assert
        $this->artisan('catalog:sync-titles')->expectsOutputToContain('Done.');
    });
});
