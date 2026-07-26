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

// Asserting the fetch alongside the cleanup keeps "no leftover temp file" from
// passing for the wrong reason (a run that never downloaded anything).
it('downloads the basics dataset and deletes the temp file afterward', function (): void {
    // Arrange
    $tempFiles = fn () => glob(sys_get_temp_dir().'/imdb_*');
    Http::fake(['*datasets.imdbws.com*' => Http::response(fixtureBytes('Catalog/imdb/title.basics.tsv.gz'))]);
    $before = $tempFiles();

    // Act
    $this->artisan('catalog:sync-titles');

    // Assert
    Http::assertSent(fn (Request $request): bool => Str::endsWith($request->url(), '/title.basics.tsv.gz'));
    expect($tempFiles())->toBe($before);
});

// Four matched, non-adult titles at --batch=2 must flush twice: the buffer
// hits the boundary on the 2nd and 4th kept row, and the trailing flush of an
// empty buffer emits nothing. The per-flush heartbeat is the observable signal.
it('flushes once per batch', function (): void {
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
    expect(substr_count($output, '[imdb titles'))->toBe(2)
        ->and($output)->toContain('[imdb titles 4]');
});
