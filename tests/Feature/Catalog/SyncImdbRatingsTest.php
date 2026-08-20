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
    // Arrange
    $tempFiles = fn () => glob(sys_get_temp_dir().'/imdb_*');
    Http::fake(['*datasets.imdbws.com*' => Http::response(fixtureBytes('Catalog/imdb/title.ratings.tsv.gz'))]);
    $before = $tempFiles();

    // Act
    $this->artisan('catalog:sync-ratings');

    // Assert
    expect($tempFiles())->toBe($before);
});

/*
|--------------------------------------------------------------------------
| Last-Modified gate
|--------------------------------------------------------------------------
| The probe is a HEAD and the download a GET, both against the same dataset
| URL, so every fake below dispatches on $request->method(): the HEAD arm
| carries the header under test, the GET arm serves the real fixture bytes.
*/

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
