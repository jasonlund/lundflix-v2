<?php

declare(strict_types=1);

use App\Domains\Catalog\Data\UnitRef;
use App\Domains\Catalog\Models\Movie;
use App\Domains\Download\Contracts\FindsAcquirableDownloads;
use App\Domains\Download\Models\Download;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 * No `Http::fake()` in this file, deliberately. Resolution reads the mirrored
 * `downloads` rows we already hold and must never reach the download source at
 * read time. `Http::preventStrayRequests()` is global for Feature tests, so the
 * absence of a fake here is what fails the suite the day a live search creeps in.
 */

describe('for() movie resolution', function (): void {
    it('resolves the row carrying the movie imdb id', function (): void {
        // Arrange
        $movie = Movie::factory()->create(['_imdb_id' => 'tt1000001', '_tmdb_id' => 501]);
        $matching = Download::factory()->create(['_imdb_id' => 'tt1000001', '_tmdb_id' => null]);
        Download::factory()->create(['_imdb_id' => 'tt9999999', '_tmdb_id' => null]);

        // Act
        $resolved = resolve(FindsAcquirableDownloads::class)->for(UnitRef::movie($movie->id));

        // Assert
        expect($resolved)->toBe($matching->id);
    });

    it('resolves the row carrying the movie tmdb id when that row has no imdb id', function (): void {
        // Arrange
        $movie = Movie::factory()->create(['_imdb_id' => 'tt2000002', '_tmdb_id' => 502]);
        $matching = Download::factory()->create(['_imdb_id' => null, '_tmdb_id' => 502]);
        Download::factory()->create(['_imdb_id' => null, '_tmdb_id' => 909]);

        // Act
        $resolved = resolve(FindsAcquirableDownloads::class)->for(UnitRef::movie($movie->id));

        // Assert
        expect($resolved)->toBe($matching->id);
    });

    it('resolves the most available of several matching rows', function (): void {
        // Arrange
        $movie = Movie::factory()->create(['_imdb_id' => 'tt3000003', '_tmdb_id' => 503]);
        Download::factory()->create(['_imdb_id' => 'tt3000003', '_provider_availability' => 5]);
        $mostAvailable = Download::factory()->create(['_imdb_id' => 'tt3000003', '_provider_availability' => 90]);
        Download::factory()->create(['_imdb_id' => 'tt3000003', '_provider_availability' => 40]);
        Download::factory()->create(['_imdb_id' => 'tt8888888', '_provider_availability' => 99]);

        // Act
        $resolved = resolve(FindsAcquirableDownloads::class)->for(UnitRef::movie($movie->id));

        // Assert
        expect($resolved)->toBe($mostAvailable->id);
    });

    it('resolves the same row every time when availability ties', function (): void {
        // The documented tie-break is `, id DESC` — the highest id wins, matching
        // the boundary-row tie-break the committed dumps already rely on. Asserting
        // the last-created row's id pins that rule rather than insertion luck, since
        // an unordered read hands back the lowest id first.
        // Arrange
        $movie = Movie::factory()->create(['_imdb_id' => 'tt4000004', '_tmdb_id' => 504]);
        Download::factory()->create(['_imdb_id' => 'tt4000004', '_provider_availability' => 42]);
        Download::factory()->create(['_imdb_id' => 'tt4000004', '_provider_availability' => 42]);
        $highestId = Download::factory()->create(['_imdb_id' => 'tt4000004', '_provider_availability' => 42]);

        // Act
        $resolved = array_map(
            fn (): ?int => resolve(FindsAcquirableDownloads::class)->for(UnitRef::movie($movie->id)),
            [1, 2, 3],
        );

        // Assert
        expect($resolved)->toBe([$highestId->id, $highestId->id, $highestId->id]);
    });
});
