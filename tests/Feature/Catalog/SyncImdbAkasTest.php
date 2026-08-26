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
| Fixture: tests/Fixtures/Catalog/imdb/title.akas.tsv.gz
|--------------------------------------------------------------------------
| Byte-exact real slice of the live IMDb title.akas dataset (.tsv.gz),
| header + 214 rows: titleId / ordering / title / region / language / types /
| attributes / isOriginalTitle. Like the live dataset the rows are sorted, so
| each title's rows are contiguous — the file holds 5 titles, in this order:
|   tt0000001   8 rows  Carmencita
|   tt0007189   5 rows  Plain Jane   (ordering 5 is the only \x02 multi-value
|                                     attributes row: reissue title + recut version)
|   tt0133093  67 rows  The Matrix
|   tt0137523  68 rows  Fight Club
|   tt0816692  66 rows  Interstellar (LAST group in the file)
|
| tt0816692 sitting last is load-bearing: its group is never closed by a
| titleId change, so it only lands if the after-loop flush exists. tt0000001
| and tt0007189 are left unseeded so unmatched titleIds are always in play.
*/

it('groups a title\'s contiguous rows into a single stored array', function (): void {
    // Arrange
    $matrix = Movie::factory()->create(['_imdb_id' => 'tt0133093']);
    Http::fake(['*datasets.imdbws.com*' => Http::response(fixtureBytes('Catalog/imdb/title.akas.tsv.gz'))]);

    // Act
    $this->artisan('catalog:sync-akas');

    // Assert
    $matrix->refresh();
    expect($matrix->_imdb_akas)->toBeArray()->toHaveCount(67)
        ->and($matrix->_imdb_akas[0])->toBe([
            'ordering' => '1', 'title' => 'The Matrix', 'region' => null, 'language' => null, 'types' => ['original'], 'attributes' => null, 'isOriginalTitle' => '1',
        ])
        ->and($matrix->_imdb_akas[66])->toBe([
            'ordering' => '9', 'title' => 'Matrix', 'region' => 'ES', 'language' => null, 'types' => ['imdbDisplay'], 'attributes' => null, 'isOriginalTitle' => '0',
        ])
        ->and(array_column($matrix->_imdb_akas, 'title'))->not->toContain('Fight Club', 'Plain Jane');
});

// The last group in the file never sees a titleId change, so it lands only if
// the buffer is flushed after the stream ends.
it('stores the final title in the file', function (): void {
    // Arrange
    $interstellar = Movie::factory()->create(['_imdb_id' => 'tt0816692']);
    Http::fake(['*datasets.imdbws.com*' => Http::response(fixtureBytes('Catalog/imdb/title.akas.tsv.gz'))]);

    // Act
    $this->artisan('catalog:sync-akas');

    // Assert
    $interstellar->refresh();
    expect($interstellar->_imdb_akas)->toBeArray()->toHaveCount(66)
        ->and($interstellar->_imdb_akas[0])->toBe([
            'ordering' => '1', 'title' => 'Interstellar', 'region' => null, 'language' => null, 'types' => ['original'], 'attributes' => null, 'isOriginalTitle' => '1',
        ])
        ->and($interstellar->_imdb_akas[65])->toBe([
            'ordering' => '9', 'title' => 'Interstellar', 'region' => 'GB', 'language' => null, 'types' => ['imdbDisplay'], 'attributes' => null, 'isOriginalTitle' => '0',
        ]);
});

// The seeded control proves the run really streamed the fixture, so the absent
// titles' missing rows can only mean they were skipped — not that the command
// did nothing.
it('stores nothing for titles absent from the catalog', function (): void {
    // Arrange
    $control = Movie::factory()->create(['_imdb_id' => 'tt0133093']);
    Http::fake(['*datasets.imdbws.com*' => Http::response(fixtureBytes('Catalog/imdb/title.akas.tsv.gz'))]);

    // Act
    $this->artisan('catalog:sync-akas');

    // Assert
    $control->refresh();
    expect($control->_imdb_akas)->toBeArray()->toHaveCount(67)
        ->and(Movie::query()->count())->toBe(1)
        ->and(Show::query()->count())->toBe(0)
        ->and(Movie::query()->whereIn('_imdb_id', ['tt0000001', 'tt0007189', 'tt0137523', 'tt0816692'])->exists())->toBeFalse();
});

// At --batch=2 a probe batch closes on the id change that follows its second
// distinct id, so the run scans 13 rows, then 148, then the tail at 214 — a
// cadence the file alone sets, unchanged by how many of its titles the catalog
// wanted. tt0137523 is the group that closes on the last of those boundaries,
// so a buffer that captured a title mid-group would land it split across two
// writes; its complete ordering sequence, in file order, proves it didn't.
it('beats the rows scanned at each probe boundary and keeps every title\'s group whole', function (): void {
    // Arrange
    $matrix = Movie::factory()->create(['_imdb_id' => 'tt0133093']);
    $fightClub = Movie::factory()->create(['_imdb_id' => 'tt0137523']);
    $interstellar = Movie::factory()->create(['_imdb_id' => 'tt0816692']);
    Http::fake(['*datasets.imdbws.com*' => Http::response(fixtureBytes('Catalog/imdb/title.akas.tsv.gz'))]);

    // Act
    Artisan::call('catalog:sync-akas', ['--batch' => 2]);

    // Assert
    $output = Artisan::output();
    expect(substr_count($output, '[imdb akas'))->toBe(3)
        ->and($output)->toContain('[imdb akas 13]')
        ->and($output)->toContain('[imdb akas 148]')
        ->and($output)->toContain('[imdb akas 214]');

    $fightClub->refresh();
    expect($fightClub->_imdb_akas)->toBeArray()->toHaveCount(68)
        ->and(array_column($fightClub->_imdb_akas, 'ordering'))->toBe([
            '1', '10', '11', '12', '13', '14', '15', '16', '17', '18', '19',
            '2', '20', '21', '22', '23', '24', '25', '26', '27', '28', '29',
            '3', '30', '31', '32', '33', '34', '35', '36', '37', '38', '39',
            '4', '40', '41', '42', '43', '44', '45', '46', '47', '48', '49',
            '5', '50', '51', '52', '53', '54', '55', '56', '57', '58', '59',
            '6', '60', '61', '62', '63', '64', '65', '66', '67', '68',
            '7', '8', '9',
        ])
        ->and($fightClub->_imdb_akas[0])->toBe([
            'ordering' => '1', 'title' => 'Fight Club', 'region' => null, 'language' => null, 'types' => ['original'], 'attributes' => null, 'isOriginalTitle' => '1',
        ])
        ->and($fightClub->_imdb_akas[67])->toBe([
            'ordering' => '9', 'title' => 'Fight Club', 'region' => 'FI', 'language' => null, 'types' => ['imdbDisplay'], 'attributes' => null, 'isOriginalTitle' => '0',
        ])
        ->and(array_column($fightClub->_imdb_akas, 'title'))->not->toContain('The Matrix', 'Interstellar');

    $matrix->refresh();
    $interstellar->refresh();
    expect($matrix->_imdb_akas)->toHaveCount(67)
        ->and($interstellar->_imdb_akas)->toHaveCount(66);
});

// title.akas is tens of millions of rows, so the run must never hold the
// catalog's whole _imdb_id column in memory: every id read is a bounded `in (…)`
// probe of the titles currently in hand. The non-empty check keeps the guard
// from passing for a run that read nothing at all.
it('never reads the catalog\'s ids unbounded', function (): void {
    // Arrange
    Movie::factory()->create(['_imdb_id' => 'tt0133093']);
    Http::fake(['*datasets.imdbws.com*' => Http::response(fixtureBytes('Catalog/imdb/title.akas.tsv.gz'))]);
    $idColumnSelects = fn (): array => collect(DB::getQueryLog())
        ->pluck('query')
        ->map(fn (mixed $query): string => (string) $query)
        ->filter(fn (string $query): bool => Str::startsWith($query, 'select') && Str::contains($query, '_imdb_id'))
        ->values()
        ->all();
    DB::enableQueryLog();

    // Act
    $this->artisan('catalog:sync-akas');

    // Assert
    expect($idColumnSelects())->not->toBeEmpty();
    foreach ($idColumnSelects() as $query) {
        expect($query)->toContain('in (');
    }
});

// The dataset runs to tens of millions of rows the catalog wants almost none
// of, so the beat counts rows scanned rather than titles applied: a run that
// matches nothing at all still has to show it is moving, at the very same
// boundaries as a run that matched everything. The write guards keep that
// liveness honest — nothing reaches the importer.
it('beats every probe boundary but writes nothing when the catalog matches no title in the dataset', function (): void {
    // Arrange
    Http::fake(['*datasets.imdbws.com*' => Http::response(fixtureBytes('Catalog/imdb/title.akas.tsv.gz'))]);

    // Act
    Artisan::call('catalog:sync-akas', ['--batch' => 2]);

    // Assert
    $output = Artisan::output();
    expect($output)->toContain('Importing IMDb akas')
        ->and(substr_count($output, '[imdb akas'))->toBe(3)
        ->and($output)->toContain('[imdb akas 13]')
        ->and($output)->toContain('[imdb akas 148]')
        ->and($output)->toContain('[imdb akas 214]')
        ->and(Movie::query()->count())->toBe(0)
        ->and(Show::query()->count())->toBe(0)
        ->and(Movie::query()->whereNotNull('_imdb_akas')->exists())->toBeFalse()
        ->and(Show::query()->whereNotNull('_imdb_akas')->exists())->toBeFalse();
});

// The akas leg is the slowest of the sync, so it closes by reporting the wall
// time it took. Only the line's presence is assertable — an elapsed reading
// taken around a live streaming import can't be frozen to an exact value.
it('prints an elapsed phase line on completion', function (): void {
    // Arrange
    Movie::factory()->create(['_imdb_id' => 'tt0133093']);
    Http::fake(['*datasets.imdbws.com*' => Http::response(fixtureBytes('Catalog/imdb/title.akas.tsv.gz'))]);

    // Act
    Artisan::call('catalog:sync-akas');

    // Assert
    expect(Artisan::output())->toContain('[elapsed');
});

// Asserting the fetch alongside the cleanup keeps "no leftover temp file" from
// passing for the wrong reason (a run that never downloaded anything).
it('downloads the akas dataset and removes the temp file when it finishes', function (): void {
    // The sink option the stub handler receives IS the tempnam() path the
    // download just created, so capturing it pins the one file under test — a
    // glob over the shared temp dir also matches files other processes leave
    // behind.
    // Arrange
    $sinkPath = null;
    Http::fake(['*datasets.imdbws.com*' => function (Request $request, array $options) use (&$sinkPath) {
        $sinkPath = $options['sink'];

        return Http::response(fixtureBytes('Catalog/imdb/title.akas.tsv.gz'));
    }]);

    // Act
    $this->artisan('catalog:sync-akas');

    // Assert
    Http::assertSent(fn (Request $request): bool => Str::endsWith($request->url(), '/title.akas.tsv.gz'));
    expect($sinkPath)->toBeString();
    expect(file_exists($sinkPath))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Last-Modified gate
|--------------------------------------------------------------------------
| The probe is a HEAD and the download a GET, both against the same dataset
| URL, so every fake below dispatches on $request->method(): the HEAD arm
| carries the header under test, the GET arm serves the real fixture bytes.
*/

it('skips the akas download when the dataset is unchanged', function (): void {
    // Arrange
    $header = 'Tue, 12 Aug 2026 01:02:03 GMT';
    resolve(ImdbDatasetMarker::class)->advance(ImdbDataset::TitleAkas, $header);
    $matrix = Movie::factory()->create(['_imdb_id' => 'tt0133093']);
    Http::fake(fn (Request $request) => $request->method() === 'HEAD'
        ? Http::response('', 200, ['Last-Modified' => $header])
        : Http::response(fixtureBytes('Catalog/imdb/title.akas.tsv.gz')));

    // Act
    $exitCode = Artisan::call('catalog:sync-akas');

    // Assert
    expect(Artisan::output())->toContain('unchanged');
    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'GET');
    expect($matrix->refresh()->_imdb_akas)->toBeNull();
    expect($exitCode)->toBe(0);
});

// The untouched basics marker is the point: each leg gates on its own dataset,
// so an akas run must never stamp another leg's marker and skip it next time.
it('advances the akas marker after a successful sync', function (): void {
    // Arrange
    $header = 'Wed, 13 Aug 2026 04:05:06 GMT';
    $matrix = Movie::factory()->create(['_imdb_id' => 'tt0133093']);
    Http::fake(fn (Request $request) => $request->method() === 'HEAD'
        ? Http::response('', 200, ['Last-Modified' => $header])
        : Http::response(fixtureBytes('Catalog/imdb/title.akas.tsv.gz')));

    // Act
    Artisan::call('catalog:sync-akas');

    // Assert
    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET' && Str::endsWith($request->url(), '/title.akas.tsv.gz'));
    expect($matrix->refresh()->_imdb_akas)->toHaveCount(67);
    expect(resolve(ImdbDatasetMarker::class)->current(ImdbDataset::TitleAkas))->toBe($header);
    expect(resolve(ImdbDatasetMarker::class)->current(ImdbDataset::TitleBasics))->toBeNull();
});

it('downloads the akas dataset despite a matching marker when forced', function (): void {
    // Arrange
    $header = 'Tue, 12 Aug 2026 01:02:03 GMT';
    resolve(ImdbDatasetMarker::class)->advance(ImdbDataset::TitleAkas, $header);
    $matrix = Movie::factory()->create(['_imdb_id' => 'tt0133093']);
    Http::fake(fn (Request $request) => $request->method() === 'HEAD'
        ? Http::response('', 200, ['Last-Modified' => $header])
        : Http::response(fixtureBytes('Catalog/imdb/title.akas.tsv.gz')));

    // Act
    Artisan::call('catalog:sync-akas', ['--force' => true]);

    // Assert
    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET' && Str::endsWith($request->url(), '/title.akas.tsv.gz'));
    expect($matrix->refresh()->_imdb_akas)->toHaveCount(67);
});
