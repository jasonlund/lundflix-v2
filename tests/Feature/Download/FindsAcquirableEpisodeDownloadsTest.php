<?php

declare(strict_types=1);

use App\Domains\Catalog\Data\UnitRef;
use App\Domains\Catalog\Enums\UnitKind;
use App\Domains\Catalog\Models\Episode;
use App\Domains\Catalog\Models\Show;
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

describe('for() episode resolution', function (): void {
    it('resolves the row naming the episode show, season and number', function (): void {
        // Arrange
        $show = Show::factory()->create(['_imdb_id' => 'tt6100001', '_tmdb_id' => 6101]);
        $episode = Episode::factory()->create(['show_id' => $show->id, '_tvdb_seasonNumber' => 2, '_tvdb_number' => 5]);
        $matching = Download::factory()->create([
            '_imdb_id' => 'tt6100001',
            '_provider_category' => Category::Tv,
            'season' => 2,
            'episode' => 5,
            'is_season_pack' => false,
        ]);
        Download::factory()->create([
            '_imdb_id' => 'tt6100001',
            '_provider_category' => Category::Tv,
            'season' => 2,
            'episode' => 6,
            'is_season_pack' => false,
        ]);
        Download::factory()->create([
            '_imdb_id' => 'tt6199999',
            '_provider_category' => Category::Tv,
            'season' => 2,
            'episode' => 5,
            'is_season_pack' => false,
        ]);

        // Act
        $resolved = resolve(FindsAcquirableDownloads::class)->for(new UnitRef(UnitKind::Episode, $episode->id));

        // Assert
        expect($resolved)->toBe($matching->id);
    });

    it('resolves any episode of a season to a pack for that season', function (int $number): void {
        // Arrange
        $show = Show::factory()->create(['_imdb_id' => 'tt6200002', '_tmdb_id' => 6202]);
        $episode = Episode::factory()->create(['show_id' => $show->id, '_tvdb_seasonNumber' => 2, '_tvdb_number' => $number]);
        $pack = Download::factory()->create([
            '_imdb_id' => 'tt6200002',
            '_provider_category' => Category::Tv,
            'season' => 2,
            'episode' => null,
            'is_season_pack' => true,
        ]);

        // Act
        $resolved = resolve(FindsAcquirableDownloads::class)->for(new UnitRef(UnitKind::Episode, $episode->id));

        // Assert
        expect($resolved)->toBe($pack->id);
    })->with([
        'first episode' => 1,
        'ninth episode' => 9,
    ]);

    it('ranks an episode file and a pack together by availability alone', function (int $episodeFileAvailability, int $packAvailability, string $expected): void {
        // Arrange
        $show = Show::factory()->create(['_imdb_id' => 'tt6300003', '_tmdb_id' => 6303]);
        $episode = Episode::factory()->create(['show_id' => $show->id, '_tvdb_seasonNumber' => 2, '_tvdb_number' => 5]);
        $rows = [
            'episode file' => Download::factory()->create([
                '_imdb_id' => 'tt6300003',
                '_provider_category' => Category::Tv,
                '_provider_availability' => $episodeFileAvailability,
                'season' => 2,
                'episode' => 5,
                'is_season_pack' => false,
            ]),
            'pack' => Download::factory()->create([
                '_imdb_id' => 'tt6300003',
                '_provider_category' => Category::Tv,
                '_provider_availability' => $packAvailability,
                'season' => 2,
                'episode' => null,
                'is_season_pack' => true,
            ]),
        ];

        // Act
        $resolved = resolve(FindsAcquirableDownloads::class)->for(new UnitRef(UnitKind::Episode, $episode->id));

        // Assert
        expect($resolved)->toBe($rows[$expected]->id);
    })->with([
        'episode file more available' => [90, 40, 'episode file'],
        'pack more available' => [40, 90, 'pack'],
    ]);

    it('resolves a television row carrying only the show tmdb id', function (): void {
        // Arrange
        $show = Show::factory()->create(['_imdb_id' => 'tt6400004', '_tmdb_id' => 6404]);
        $episode = Episode::factory()->create(['show_id' => $show->id, '_tvdb_seasonNumber' => 2, '_tvdb_number' => 5]);
        $matching = Download::factory()->create([
            '_imdb_id' => null,
            '_tmdb_id' => 6404,
            '_provider_category' => Category::Tv,
            'season' => 2,
            'episode' => 5,
            'is_season_pack' => false,
        ]);

        // Act
        $resolved = resolve(FindsAcquirableDownloads::class)->for(new UnitRef(UnitKind::Episode, $episode->id));

        // Assert
        expect($resolved)->toBe($matching->id);
    });

    it('resolves to nothing when the only pack covers a different season', function (): void {
        // Arrange
        $show = Show::factory()->create(['_imdb_id' => 'tt6500005', '_tmdb_id' => 6505]);
        $episode = Episode::factory()->create(['show_id' => $show->id, '_tvdb_seasonNumber' => 3, '_tvdb_number' => 1]);
        Download::factory()->create([
            '_imdb_id' => 'tt6500005',
            '_provider_category' => Category::Tv,
            'season' => 2,
            'episode' => null,
            'is_season_pack' => true,
        ]);

        // Act
        $resolved = resolve(FindsAcquirableDownloads::class)->for(new UnitRef(UnitKind::Episode, $episode->id));

        // Assert
        expect($resolved)->toBeNull();
    });

    it('resolves an episode with no recorded season or number to nothing', function (): void {
        // The row's name never parsed, so its season, episode and pack flag are all
        // null. An episode missing its own season and number must not match it: a null
        // compared through `where($column, null)` becomes `whereNull`, which would pair
        // every unrecorded episode with every unparsed row of the show.
        // Arrange
        $show = Show::factory()->create(['_imdb_id' => 'tt6600006', '_tmdb_id' => 6606]);
        $episode = Episode::factory()->create(['show_id' => $show->id, '_tvdb_seasonNumber' => null, '_tvdb_number' => null]);
        Download::factory()->create([
            '_imdb_id' => 'tt6600006',
            '_provider_category' => Category::Tv,
            'season' => null,
            'episode' => null,
            'is_season_pack' => null,
        ]);

        // Act
        $resolved = resolve(FindsAcquirableDownloads::class)->for(new UnitRef(UnitKind::Episode, $episode->id));

        // Assert
        expect($resolved)->toBeNull();
    });
});
