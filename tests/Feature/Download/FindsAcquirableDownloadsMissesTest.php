<?php

declare(strict_types=1);

use App\Domains\Catalog\Data\UnitRef;
use App\Domains\Catalog\Enums\UnitKind;
use App\Domains\Catalog\Models\Movie;
use App\Domains\Download\Contracts\FindsAcquirableDownloads;
use App\Domains\Download\Enums\Category;
use App\Domains\Download\Models\Download;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 * No `Http::fake()` in this file, deliberately. Resolution reads the mirrored
 * `downloads` rows we already hold and must never reach the download source at
 * read time. `Http::preventStrayRequests()` is global for Feature tests, so the
 * absence of a fake here is what fails the suite the day a live search creeps in.
 */

describe('for() misses', function (): void {
    it('resolves a movie whose crosswalk ids match no row to nothing', function (): void {
        // Arrange
        $movie = Movie::factory()->create(['_imdb_id' => 'tt5000005', '_tmdb_id' => 505]);
        Download::factory()->create(['_imdb_id' => 'tt7777777', '_tmdb_id' => null]);
        Download::factory()->create(['_imdb_id' => null, '_tmdb_id' => 707]);

        // Act
        $resolved = resolve(FindsAcquirableDownloads::class)->for(new UnitRef(UnitKind::Movie, $movie->id));

        // Assert
        expect($resolved)->toBeNull();
    });

    it('resolves to nothing when the row imdb id claims a different movie', function (): void {
        // A row carrying a non-null imdb id is attributed by that id alone; its tmdb
        // id only decides attribution when the imdb id is absent. So this row belongs
        // to movie B, and movie A must not inherit it off the shared tmdb id.
        // Arrange
        $movieA = Movie::factory()->create(['_imdb_id' => 'tt1111111', '_tmdb_id' => 111]);
        Movie::factory()->create(['_imdb_id' => 'tt2222222', '_tmdb_id' => 222]);
        Download::factory()->create(['_imdb_id' => 'tt2222222', '_tmdb_id' => 111]);

        // Act
        $resolved = resolve(FindsAcquirableDownloads::class)->for(new UnitRef(UnitKind::Movie, $movieA->id));

        // Assert
        expect($resolved)->toBeNull();
    });

    it('resolves to nothing when only a television row carries the movie tmdb id', function (): void {
        // TMDB numbers movies and series in separate sequences, so one number names
        // two unrelated works. `_provider_category` is the only record of which type a
        // row was mirrored from, so the tmdb id alone cannot attribute it to a movie.
        // The imdb precedence guard is no help here: it applies only to a row that
        // carries an imdb id, and this one does not.
        // Arrange
        $movie = Movie::factory()->create(['_imdb_id' => 'tt3333333', '_tmdb_id' => 550]);
        Download::factory()->create([
            '_imdb_id' => null,
            '_tmdb_id' => 550,
            '_provider_category' => Category::Tv,
        ]);

        // Act
        $resolved = resolve(FindsAcquirableDownloads::class)->for(new UnitRef(UnitKind::Movie, $movie->id));

        // Assert
        expect($resolved)->toBeNull();
    });
});
