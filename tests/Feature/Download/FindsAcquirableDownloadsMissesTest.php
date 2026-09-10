<?php

declare(strict_types=1);

use App\Domains\Catalog\Data\UnitRef;
use App\Domains\Catalog\Enums\UnitKind;
use App\Domains\Catalog\Models\Episode;
use App\Domains\Catalog\Models\Movie;
use App\Domains\Catalog\Models\Show;
use App\Domains\Download\Contracts\FindsAcquirableDownloads;
use App\Domains\Download\Models\Download;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 * No `Http::fake()` in this file, deliberately. Resolution reads the mirrored
 * `downloads` rows we already hold and must never reach the download source at
 * read time. `Http::preventStrayRequests()` is global for Feature tests, so the
 * absence of a fake here is what fails the suite the day a live search creeps in.
 *
 * The episode case below pins behavior that is intentionally inert rather than
 * broken: episode identity is not yet mirrored onto a row, so every episode
 * resolves to nothing on purpose. FLIX-313 lands that identity and deletes the
 * test outright — its name says so, so nobody patches around a failing assertion.
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

    it('resolves an episode to nothing until episode identity lands (provisional — FLIX-313 removes this)', function (): void {
        // Arrange
        $show = Show::factory()->create(['_imdb_id' => 'tt6000006', '_tmdb_id' => 606]);
        $episode = Episode::factory()->create(['show_id' => $show->id, '_tvdb_seasonNumber' => 2, '_tvdb_number' => 5]);
        Download::factory()->create([
            '_imdb_id' => 'tt6000006',
            '_tmdb_id' => 606,
            '_provider_name' => 'Some.Show.S02E05.1080p.WEB-DL.x265',
        ]);

        // Act
        $resolved = resolve(FindsAcquirableDownloads::class)->for(new UnitRef(UnitKind::Episode, $episode->id));

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
});
