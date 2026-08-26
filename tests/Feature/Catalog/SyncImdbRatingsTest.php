<?php

declare(strict_types=1);

use App\Domains\Catalog\Models\Movie;
use App\Domains\Catalog\Models\Show;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
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
