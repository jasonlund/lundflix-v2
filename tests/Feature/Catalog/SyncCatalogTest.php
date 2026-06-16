<?php

use App\Domains\Catalog\Models\Movie;
use App\Domains\Catalog\Models\Show;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Console\Command\Command;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Fixtures (byte-exact real IMDb dataset slices)
|--------------------------------------------------------------------------
| tests/Fixtures/Catalog/imdb/title.basics.tsv.gz  — 10 movies / 3 shows,
|   incl. tt0133093 (The Matrix), tt0137523 (Fight Club),
|   tt0816692 (Interstellar).
| tests/Fixtures/Catalog/imdb/title.ratings.tsv.gz — tt0133093 8.7/2252453,
|   tt0137523 8.8/2615814, tt0816692 8.7/2541567, tt0000001 5.7/2211.
|
| sync:catalog dispatches imdb:import-titles then imdb:import-ratings; the two
| commands download DISTINCT files, so we fake per-file (not a host wildcard).
*/

it('runs titles then ratings end-to-end', function () {
    // Arrange
    Http::fake([
        '*title.basics*' => Http::response(fixtureBytes('Catalog/imdb/title.basics.tsv.gz')),
        '*title.ratings*' => Http::response(fixtureBytes('Catalog/imdb/title.ratings.tsv.gz')),
    ]);

    // Act
    $this->artisan('sync:catalog');

    // Assert
    expect(Movie::count())->toBe(10);
    expect(Show::count())->toBe(3);
    expect(Movie::pluck('imdb_id')->all())->toContain('tt0133093', 'tt0137523', 'tt0816692');

    $matrix = Movie::where('imdb_id', 'tt0133093')->firstOrFail();
    expect($matrix->num_votes)->toBe(2252453);
    expect($matrix->average_rating)->toBe(8.7);
});

it('continues to ratings when titles fails, exits FAILURE and reports the exception', function () {
    // Arrange
    Exceptions::fake();
    Http::fake([
        '*title.basics*' => Http::response('', 500),
        '*title.ratings*' => Http::response(fixtureBytes('Catalog/imdb/title.ratings.tsv.gz')),
    ]);

    // Act
    $this->artisan('sync:catalog')->assertExitCode(Command::FAILURE);

    // Assert
    Exceptions::assertReported(fn (RequestException $e): bool => true);
});

it('exits SUCCESS when both commands succeed', function () {
    // Arrange
    Http::fake([
        '*title.basics*' => Http::response(fixtureBytes('Catalog/imdb/title.basics.tsv.gz')),
        '*title.ratings*' => Http::response(fixtureBytes('Catalog/imdb/title.ratings.tsv.gz')),
    ]);

    // Act & Assert
    $this->artisan('sync:catalog')->assertExitCode(Command::SUCCESS);
});
