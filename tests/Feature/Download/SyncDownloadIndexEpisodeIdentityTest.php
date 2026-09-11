<?php

declare(strict_types=1);

use App\Domains\Download\Models\Download;
use App\Domains\Download\Settings\DownloadSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

/*
| The index walks are faked with the same byte-exact captures as
| SyncDownloadIndexTest. The three ids asserted here are facts of those
| captures: 7563843 is "Mocro Mafia S02E04 …" and 7563844 is "The Five Star
| Weekend S01 …" (a whole-season pack), both on index_tv_p1; 7563851 is "The
| Crying Game 1992 COMPLETE …" on index_movies_p1, a name with no episode
| identity. The walks total 150 disjoint ids (Movies 50 + 50, Tv 50).
*/

describe('download:sync-index episode identity', function (): void {
    beforeEach(function (): void {
        $settings = resolve(DownloadSettings::class);
        $settings->uid = 'u123';
        $settings->pass = 'p123';
        $settings->save();
        Http::fake([
            '*72=&p=1' => Http::response(fixtureBytes('Download/downloads/index_movies_p1.html'), 200),
            '*72=&p=2' => Http::response(fixtureBytes('Download/downloads/index_movies_p2.html'), 200),
            '*73=&p=1' => Http::response(fixtureBytes('Download/downloads/index_tv_p1.html'), 200),
            '*' => Http::response(fixtureBytes('Download/downloads/index_movies_p1_no_table.html'), 200),
        ]);
    });

    it('writes the season and episode onto a synced episode row', function (): void {
        // Arrange
        // credentials and index captures set in beforeEach

        // Act
        $this->artisan('download:sync-index', ['--fresh' => true])->assertSuccessful();

        // Assert
        $this->assertDatabaseHas('downloads', [
            '_provider_id' => 7563843,
            'season' => 2,
            'episode' => 4,
            'is_season_pack' => false,
        ]);
    });

    it('writes the season and the pack marker onto a synced season-pack row', function (): void {
        // Arrange
        // credentials and index captures set in beforeEach

        // Act
        $this->artisan('download:sync-index', ['--fresh' => true])->assertSuccessful();

        // Assert
        $this->assertDatabaseHas('downloads', [
            '_provider_id' => 7563844,
            'season' => 1,
            'episode' => null,
            'is_season_pack' => true,
        ]);
    });

    /*
    | The ordinary (non --fresh) walk upserts every result on a page before its
    | stop check, so a row already mirrored is rewritten by the next routine sync.
    | That is the whole backfill: no one-off command exists or is needed.
    */
    it('fills in identity on a row that predates it at the next ordinary sync', function (): void {
        // Arrange
        Download::factory()->create(['_provider_id' => 7563843]);

        // Act
        $this->artisan('download:sync-index')->assertSuccessful();

        // Assert
        $this->assertDatabaseHas('downloads', [
            '_provider_id' => 7563843,
            'season' => 2,
            'episode' => 4,
            'is_season_pack' => false,
        ]);
        $this->assertDatabaseCount('downloads', 150);
    });

    it('leaves identity empty and still imports a name with no episode identity', function (): void {
        // Arrange
        // credentials and index captures set in beforeEach

        // Act
        $this->artisan('download:sync-index', ['--fresh' => true])->assertSuccessful();

        // Assert
        $this->assertDatabaseHas('downloads', [
            '_provider_id' => 7563851,
            'season' => null,
            'episode' => null,
            'is_season_pack' => null,
        ]);
    });
});
