<?php

declare(strict_types=1);

use App\Domains\Catalog\Enums\ImdbDataset;
use App\Domains\Catalog\Models\Movie;
use App\Domains\Catalog\Models\Show;
use App\Domains\Catalog\Support\ImdbDatasetMarker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Fixture: tests/Fixtures/Catalog/imdb/title.ratings.tsv.gz
|--------------------------------------------------------------------------
| Byte-exact real slice of the live IMDb title.ratings dataset (.tsv.gz),
| 4 rows: tconst / averageRating / numVotes —
|   tt0133093  8.7  2252453  (The Matrix)
|   tt0137523  8.8  2615814  (Fight Club)
|   tt0816692  8.7  2541567  (Interstellar)
|   tt0000001  5.7  2211
*/

describe('catalog:sync-ratings ratings ingest', function (): void {
    it('populates ratings on pre-seeded titles', function (): void {
        // Arrange
        $matrix = Movie::factory()->create(['_imdb_id' => 'tt0133093', '_imdb_numVotes' => 1, '_imdb_averageRating' => 1.0]);
        $interstellar = Movie::factory()->create(['_imdb_id' => 'tt0816692', '_imdb_numVotes' => 1, '_imdb_averageRating' => 1.0]);
        $fightClub = Show::factory()->create(['_imdb_id' => 'tt0137523', '_imdb_numVotes' => 1, '_imdb_averageRating' => 1.0]);
        Http::fake(['*datasets.imdbws.com*' => Http::response(fixtureBytes('Catalog/imdb/title.ratings.tsv.gz'))]);

        // Act
        $this->artisan('catalog:sync-ratings');

        // Assert
        $matrix->refresh();
        expect($matrix->_imdb_numVotes)->toBe(2252453);
        expect($matrix->_imdb_averageRating)->toBe(8.7);

        $interstellar->refresh();
        expect($interstellar->_imdb_numVotes)->toBe(2541567);
        expect($interstellar->_imdb_averageRating)->toBe(8.7);

        $fightClub->refresh();
        expect($fightClub->_imdb_numVotes)->toBe(2615814);
        expect($fightClub->_imdb_averageRating)->toBe(8.8);
    });

    it('exits SUCCESS', function (): void {
        // Arrange
        Http::fake(['*datasets.imdbws.com*' => Http::response(fixtureBytes('Catalog/imdb/title.ratings.tsv.gz'))]);

        // Act & Assert
        $this->artisan('catalog:sync-ratings')->assertExitCode(0);
    });

    it('emits a progress heartbeat', function (): void {
        // Arrange
        Http::fake(['*datasets.imdbws.com*' => Http::response(fixtureBytes('Catalog/imdb/title.ratings.tsv.gz'))]);

        // Act & Assert
        $this->artisan('catalog:sync-ratings')->expectsOutputToContain('[imdb ratings');
    });

    it('deletes the temp file afterward', function (): void {
        // The sink option the stub handler receives IS the tempnam() path download()
        // just created, so capturing it pins the one file under test — a glob over
        // the shared temp dir also matches files other processes leave behind.
        // Arrange
        $sinkPath = null;
        Http::fake(['*datasets.imdbws.com*' => function (Request $request, array $options) use (&$sinkPath) {
            $sinkPath = $options['sink'];

            return Http::response(fixtureBytes('Catalog/imdb/title.ratings.tsv.gz'));
        }]);

        // Act
        $this->artisan('catalog:sync-ratings');

        // Assert
        expect($sinkPath)->toBeString();
        expect(file_exists($sinkPath))->toBeFalse();
    });
});

/*
|--------------------------------------------------------------------------
| Last-Modified gate
|--------------------------------------------------------------------------
| The probe is a HEAD and the download a GET, both against the same dataset
| URL, so every fake below dispatches on $request->method(): the HEAD arm
| carries the header under test, the GET arm serves the real fixture bytes.
*/

/*
|--------------------------------------------------------------------------
| Scanned-row heartbeat
|--------------------------------------------------------------------------
| The counts asserted below are dataset rows SCANNED, not ratings applied:
| at --batch=2 the pre-filter closes a probe batch after the fixture's first
| two rows and again after its last two, so a catalog-miss stretch still
| moves the number.
*/

describe('catalog:sync-ratings marker gating', function (): void {
    it('skips the download when the dataset is unchanged', function (): void {
        // Arrange
        $header = 'Tue, 12 Aug 2026 01:02:03 GMT';
        resolve(ImdbDatasetMarker::class)->advance(ImdbDataset::TitleRatings, $header);
        $matrix = Movie::factory()->create(['_imdb_id' => 'tt0133093', '_imdb_numVotes' => 1, '_imdb_averageRating' => 1.0]);
        Http::fake(fn (Request $request) => $request->method() === 'HEAD'
            ? Http::response('', 200, ['Last-Modified' => $header])
            : Http::response(fixtureBytes('Catalog/imdb/title.ratings.tsv.gz')));

        // Act
        $exitCode = Artisan::call('catalog:sync-ratings');

        // Assert
        expect(Artisan::output())->toContain('unchanged');
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'GET');
        expect($matrix->refresh()->_imdb_numVotes)->toBe(1);
        expect($exitCode)->toBe(0);
    });

    it('syncs and marks the dataset when no marker is stored', function (): void {
        // Arrange
        $header = 'Tue, 12 Aug 2026 01:02:03 GMT';
        $matrix = Movie::factory()->create(['_imdb_id' => 'tt0133093', '_imdb_numVotes' => 1, '_imdb_averageRating' => 1.0]);
        Http::fake(fn (Request $request) => $request->method() === 'HEAD'
            ? Http::response('', 200, ['Last-Modified' => $header])
            : Http::response(fixtureBytes('Catalog/imdb/title.ratings.tsv.gz')));

        // Act
        Artisan::call('catalog:sync-ratings');

        // Assert
        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET' && Str::contains($request->url(), 'title.ratings.tsv.gz'));
        expect($matrix->refresh()->_imdb_numVotes)->toBe(2252453);
        expect(resolve(ImdbDatasetMarker::class)->current(ImdbDataset::TitleRatings))->toBe($header);
    });

    it('syncs and advances the marker when the dataset has changed', function (): void {
        // Arrange
        $header = 'Wed, 13 Aug 2026 04:05:06 GMT';
        resolve(ImdbDatasetMarker::class)->advance(ImdbDataset::TitleRatings, 'Tue, 12 Aug 2026 01:02:03 GMT');
        $fightClub = Show::factory()->create(['_imdb_id' => 'tt0137523', '_imdb_numVotes' => 1, '_imdb_averageRating' => 1.0]);
        Http::fake(fn (Request $request) => $request->method() === 'HEAD'
            ? Http::response('', 200, ['Last-Modified' => $header])
            : Http::response(fixtureBytes('Catalog/imdb/title.ratings.tsv.gz')));

        // Act
        Artisan::call('catalog:sync-ratings');

        // Assert
        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET' && Str::contains($request->url(), 'title.ratings.tsv.gz'));
        expect($fightClub->refresh()->_imdb_numVotes)->toBe(2615814);
        expect(resolve(ImdbDatasetMarker::class)->current(ImdbDataset::TitleRatings))->toBe($header);
    });

    it('leaves the marker untouched when the download fails', function (): void {
        // Arrange
        Sleep::fake();
        resolve(ImdbDatasetMarker::class)->advance(ImdbDataset::TitleRatings, 'Tue, 12 Aug 2026 01:02:03 GMT');
        Http::fake(fn (Request $request) => $request->method() === 'HEAD'
            ? Http::response('', 200, ['Last-Modified' => 'Wed, 13 Aug 2026 04:05:06 GMT'])
            : Http::response('', 500));

        // Act
        try {
            Artisan::call('catalog:sync-ratings');
        } catch (RequestException) {
            // the stale marker, not the throw, is under test
        }

        // Assert
        expect(resolve(ImdbDatasetMarker::class)->current(ImdbDataset::TitleRatings))->toBe('Tue, 12 Aug 2026 01:02:03 GMT');
    });

    it('downloads despite a matching marker when forced', function (): void {
        // Arrange
        $header = 'Tue, 12 Aug 2026 01:02:03 GMT';
        resolve(ImdbDatasetMarker::class)->advance(ImdbDataset::TitleRatings, $header);
        $matrix = Movie::factory()->create(['_imdb_id' => 'tt0133093', '_imdb_numVotes' => 1, '_imdb_averageRating' => 1.0]);
        Http::fake(fn (Request $request) => $request->method() === 'HEAD'
            ? Http::response('', 200, ['Last-Modified' => $header])
            : Http::response(fixtureBytes('Catalog/imdb/title.ratings.tsv.gz')));

        // Act
        Artisan::call('catalog:sync-ratings', ['--force' => true]);

        // Assert
        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET' && Str::contains($request->url(), 'title.ratings.tsv.gz'));
        expect($matrix->refresh()->_imdb_numVotes)->toBe(2252453);
    });
});

describe('catalog:sync-ratings heartbeat output', function (): void {
    it('heartbeats cumulative scanned rows at each probe boundary', function (): void {
        // Arrange
        Movie::factory()->create(['_imdb_id' => 'tt0133093', '_imdb_numVotes' => 1, '_imdb_averageRating' => 1.0]);
        Http::fake(['*datasets.imdbws.com*' => Http::response(fixtureBytes('Catalog/imdb/title.ratings.tsv.gz'))]);

        // Act
        Artisan::call('catalog:sync-ratings', ['--batch' => 2]);

        // Assert
        expect(Artisan::output())
            ->toContain('[imdb ratings 2]')
            ->toContain('[imdb ratings 4]');
    });

    it('keeps beating through a run that matches nothing', function (): void {
        // Arrange
        $unmatched = Movie::factory()->create(['_imdb_id' => 'tt9999999', '_imdb_numVotes' => 1, '_imdb_averageRating' => 1.0]);
        Http::fake(['*datasets.imdbws.com*' => Http::response(fixtureBytes('Catalog/imdb/title.ratings.tsv.gz'))]);

        // Act
        Artisan::call('catalog:sync-ratings', ['--batch' => 2]);

        // Assert
        expect(Artisan::output())
            ->toContain('[imdb ratings 2]')
            ->toContain('[imdb ratings 4]');
        expect($unmatched->refresh()->_imdb_numVotes)->toBe(1);
        expect($unmatched->_imdb_averageRating)->toBe(1.0);
    });

    it('prints an elapsed phase line on completion', function (): void {
        // Shape only: the elapsed seconds are real wall clock around a streaming
        // read, so there is no value to freeze and assert.
        // Arrange
        Http::fake(['*datasets.imdbws.com*' => Http::response(fixtureBytes('Catalog/imdb/title.ratings.tsv.gz'))]);

        // Act & Assert
        $this->artisan('catalog:sync-ratings')->expectsOutputToContain('[elapsed');
    });
});
