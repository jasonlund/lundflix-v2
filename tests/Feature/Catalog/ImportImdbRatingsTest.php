<?php

declare(strict_types=1);

use App\Domains\Catalog\Models\Movie;
use App\Domains\Catalog\Models\Show;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

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

it('populates ratings on pre-seeded titles', function () {
    // Arrange
    $matrix = Movie::factory()->create(['imdb_id' => 'tt0133093', 'num_votes' => 1, 'average_rating' => 1.0]);
    $interstellar = Movie::factory()->create(['imdb_id' => 'tt0816692', 'num_votes' => 1, 'average_rating' => 1.0]);
    $fightClub = Show::factory()->create(['imdb_id' => 'tt0137523', 'num_votes' => 1, 'average_rating' => 1.0]);
    Http::fake(['*datasets.imdbws.com*' => Http::response(fixtureBytes('Catalog/imdb/title.ratings.tsv.gz'))]);

    // Act
    $this->artisan('imdb:import-ratings');

    // Assert
    $matrix->refresh();
    expect($matrix->num_votes)->toBe(2252453);
    expect($matrix->average_rating)->toBe(8.7);

    $interstellar->refresh();
    expect($interstellar->num_votes)->toBe(2541567);
    expect($interstellar->average_rating)->toBe(8.7);

    $fightClub->refresh();
    expect($fightClub->num_votes)->toBe(2615814);
    expect($fightClub->average_rating)->toBe(8.8);
});

it('exits SUCCESS', function () {
    // Arrange
    Http::fake(['*datasets.imdbws.com*' => Http::response(fixtureBytes('Catalog/imdb/title.ratings.tsv.gz'))]);

    // Act & Assert
    $this->artisan('imdb:import-ratings')->assertExitCode(0);
});

it('deletes the temp file afterward', function () {
    // Arrange
    $tempFiles = fn () => glob(sys_get_temp_dir().'/imdb_*');
    Http::fake(['*datasets.imdbws.com*' => Http::response(fixtureBytes('Catalog/imdb/title.ratings.tsv.gz'))]);
    $before = $tempFiles();

    // Act
    $this->artisan('imdb:import-ratings');

    // Assert
    expect($tempFiles())->toBe($before);
});
