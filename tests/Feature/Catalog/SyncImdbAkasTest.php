<?php

declare(strict_types=1);

use App\Domains\Catalog\Models\Movie;
use App\Domains\Catalog\Models\Show;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
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

// Three matched titles at --batch=2 puts a flush inside the stream: the buffer
// fills the moment tt0137523's group closes, which is the first row of
// tt0816692 — so the write happens with two thirds of the file still unread,
// and the run's tail is written by the after-loop flush. tt0137523 is the group
// that closes on the boundary, so a buffer that captured a title mid-group
// would land it here split across the two writes; its complete ordering
// sequence, in file order, is what proves it didn't.
it('keeps each title\'s group whole across a mid-stream flush', function (): void {
    // Arrange
    $matrix = Movie::factory()->create(['_imdb_id' => 'tt0133093']);
    $fightClub = Movie::factory()->create(['_imdb_id' => 'tt0137523']);
    $interstellar = Movie::factory()->create(['_imdb_id' => 'tt0816692']);
    Http::fake(['*datasets.imdbws.com*' => Http::response(fixtureBytes('Catalog/imdb/title.akas.tsv.gz'))]);

    // Act
    Artisan::call('catalog:sync-akas', ['--batch' => 2]);

    // Assert
    $output = Artisan::output();
    expect(substr_count($output, '[imdb akas'))->toBe(2)
        ->and($output)->toContain('[imdb akas 2]')
        ->and($output)->toContain('[imdb akas 3]');

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

// Asserting the fetch alongside the cleanup keeps "no leftover temp file" from
// passing for the wrong reason (a run that never downloaded anything).
it('downloads the akas dataset and removes the temp file when it finishes', function (): void {
    // Arrange
    $tempFiles = fn () => glob(sys_get_temp_dir().'/imdb_*');
    Http::fake(['*datasets.imdbws.com*' => Http::response(fixtureBytes('Catalog/imdb/title.akas.tsv.gz'))]);
    $before = $tempFiles();

    // Act
    $this->artisan('catalog:sync-akas');

    // Assert
    Http::assertSent(fn (Request $request): bool => Str::endsWith($request->url(), '/title.akas.tsv.gz'));
    expect($tempFiles())->toBe($before);
});
