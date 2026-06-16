<?php

use App\Domains\Catalog\Enums\Genre;
use App\Domains\Catalog\Enums\TitleType;
use App\Domains\Catalog\Models\Movie;
use App\Domains\Catalog\Models\Show;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Fixture: tests/Fixtures/Catalog/imdb/title.basics.tsv.gz
|--------------------------------------------------------------------------
| Byte-exact real slice of the live IMDb title.basics dataset. 13 kept rows
| after ImdbDataset filtering, split by TitleType::isShow():
|   10 movies — tt0133093 (The Matrix, Action,Sci-Fi), tt0137523 (Fight Club),
|     tt0816692 (Interstellar), tt0030298 (Julius Caesar, tvMovie), tt0000001,
|     tt0060178, tt0066435, tt0000502, tt0000615, tt0063362.
|   3 shows  — tt0030138 (Flash Gordon, tvSeries),
|     tt0047766 (Quatermass II, tvMiniSeries),
|     tt0038276 (You Are an Artist, tvSeries).
*/

it('splits movies vs shows from the fixture', function () {
    Http::fake(['*datasets.imdbws.com*' => Http::response(fixtureBytes('Catalog/imdb/title.basics.tsv.gz'))]);

    $this->artisan('imdb:import-titles');

    expect(Movie::count())->toBe(10);
    expect(Movie::pluck('imdb_id')->all())->toContain('tt0133093', 'tt0137523', 'tt0816692');
    expect(Show::count())->toBe(3);
    expect(Show::pluck('imdb_id')->all())->toContain('tt0030138', 'tt0047766', 'tt0038276');
});

it('maps genres to Genre cases', function () {
    Http::fake(['*datasets.imdbws.com*' => Http::response(fixtureBytes('Catalog/imdb/title.basics.tsv.gz'))]);
    $this->artisan('imdb:import-titles');

    $movie = Movie::where('imdb_id', 'tt0133093')->first();

    expect($movie)->not->toBeNull();
    expect($movie->genres->all())->toBe([Genre::Action, Genre::SciFi]);
});

it('preserves the specific originating title type per table', function () {
    Http::fake(['*datasets.imdbws.com*' => Http::response(fixtureBytes('Catalog/imdb/title.basics.tsv.gz'))]);

    $this->artisan('imdb:import-titles');

    expect(Movie::where('imdb_id', 'tt0030298')->firstOrFail()->title_type)->toBe(TitleType::TvMovie);
    expect(Show::where('imdb_id', 'tt0047766')->firstOrFail()->title_type)->toBe(TitleType::TvMiniSeries);
});

it('exits SUCCESS', function () {
    Http::fake(['*datasets.imdbws.com*' => Http::response(fixtureBytes('Catalog/imdb/title.basics.tsv.gz'))]);

    $this->artisan('imdb:import-titles')->assertExitCode(0);
});

it('deletes the temp file afterward', function () {
    $tempFiles = fn () => glob(sys_get_temp_dir().'/imdb_*');
    Http::fake(['*datasets.imdbws.com*' => Http::response(fixtureBytes('Catalog/imdb/title.basics.tsv.gz'))]);
    $before = $tempFiles();

    $this->artisan('imdb:import-titles');

    expect($tempFiles())->toBe($before);
});

it('is idempotent on re-run', function () {
    Http::fake(['*datasets.imdbws.com*' => fn () => Http::response(fixtureBytes('Catalog/imdb/title.basics.tsv.gz'))]);
    $this->artisan('imdb:import-titles');

    $this->artisan('imdb:import-titles');

    expect(Movie::count())->toBe(10);
    expect(Show::count())->toBe(3);
});
