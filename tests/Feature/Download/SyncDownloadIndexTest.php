<?php

declare(strict_types=1);

use App\Domains\Download\Models\Download;
use App\Domains\Download\Settings\DownloadSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/*
| The two index category walks are faked with byte-exact captures of the real
| Movies (72) and Tv (73) listing pages. index_movies_p1 advertises a later
| lastPage so the walk continues; p2's 50 ids are disjoint from p1's, so
| _provider_id 7563723 proves page 2 was fetched and persisted. The catch-all
| stub returns a table-less page (0 results) that terminates each walk after
| its last real page.
*/

describe('download:sync-index --fresh walk', function (): void {
    it('walks past page 1 and persists a page-2 row under --fresh', function (): void {
        // Arrange
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

        // Act
        $this->artisan('download:sync-index', ['--fresh' => true])->assertSuccessful();

        // Assert
        $this->assertDatabaseHas('downloads', ['_provider_id' => 7563851]);
        $this->assertDatabaseHas('downloads', ['_provider_id' => 7563723]);
        Http::assertSent(fn ($request): bool => Str::contains((string) $request->url(), '72=&p=2'));
    });

    it('walks both categories and stamps each row with its own category', function (): void {
        // Arrange
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

        // Act
        $this->artisan('download:sync-index', ['--fresh' => true])->assertSuccessful();

        // Assert
        $this->assertDatabaseHas('downloads', ['_provider_id' => 7563851, '_provider_category' => '72']);
        $this->assertDatabaseHas('downloads', ['_provider_id' => 7563850, '_provider_category' => '73']);
    });

    it('stamps only the index channel timestamp on an ingested row', function (): void {
        // Arrange
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

        // Act
        $this->artisan('download:sync-index', ['--fresh' => true])->assertSuccessful();

        // Assert
        $row = Download::query()->where('_provider_id', 7563851)->firstOrFail();
        expect($row->index_synced_at)->not->toBeNull();
        expect($row->rss_synced_at)->toBeNull();
        expect($row->detail_synced_at)->toBeNull();
    });
});

describe('download:sync-index incremental walk', function (): void {
    it('stops a category once a fetched page yields no unseen ids', function (): void {
        // Arrange
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
        $seenPage1Ids = [
            7563851, 7563849, 7563847, 7563846, 7563845, 7563830, 7563829, 7563828, 7563823, 7563814,
            7563811, 7563810, 7563792, 7563790, 7563788, 7563787, 7563783, 7563782, 7563778, 7563777,
            7563773, 7563772, 7563771, 7563769, 7563768, 7563767, 7563766, 7563765, 7563763, 7563762,
            7563761, 7563760, 7563759, 7563758, 7563757, 7563756, 7563755, 7563754, 7563753, 7563752,
            7563751, 7563750, 7563749, 7563748, 7563747, 7563746, 7563731, 7563728, 7563725, 7563724,
        ];
        foreach ($seenPage1Ids as $id) {
            Download::factory()->create(['_provider_id' => $id]);
        }

        // Act
        $this->artisan('download:sync-index')->assertSuccessful();

        // Assert
        Http::assertNotSent(fn ($request): bool => Str::contains((string) $request->url(), '72=&p=2'));
    });

    it('continues to the next page while a page still carries unseen ids', function (): void {
        // Arrange
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

        // Act
        $this->artisan('download:sync-index')->assertSuccessful();

        // Assert
        Http::assertSent(fn ($request): bool => Str::contains((string) $request->url(), '72=&p=2'));
    });

    it('drops no rows through the incremental stop logic', function (): void {
        // Arrange
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

        // Act
        $this->artisan('download:sync-index')->assertSuccessful();

        // Assert
        $this->assertDatabaseHas('downloads', ['_provider_id' => 7563851]);
        $this->assertDatabaseHas('downloads', ['_provider_id' => 7563723]);
    });
});

describe('download:sync-index console output', function (): void {
    it('prints an intro and outro around the index walk', function (): void {
        // Arrange
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

        // Act & Assert
        $this->artisan('download:sync-index', ['--fresh' => true])
            ->expectsOutputToContain('Syncing download index…')
            ->expectsOutputToContain('Done.')
            ->assertSuccessful();
    });

    /*
    | `[download index Movies p10]` itself contains the substring `index Movies`,
    | so the guard against the old unprefixed tag has to carry its trailing space
    | to avoid matching the very line it must allow.
    */
    it('names the source in the page marker printed every 10th page walked', function (): void {
        // Arrange
        $settings = resolve(DownloadSettings::class);
        $settings->uid = 'u123';
        $settings->pass = 'p123';
        $settings->save();
        $fakes = [];
        for ($p = 1; $p <= 10; $p++) {
            $fakes["*72=&p={$p}"] = Http::response(fixtureBytes('Download/downloads/index_movies_p1.html'), 200);
        }
        $fakes['*'] = Http::response(fixtureBytes('Download/downloads/index_movies_p1_no_table.html'), 200);
        Http::fake($fakes);

        // Act & Assert
        $this->artisan('download:sync-index', ['--fresh' => true])
            ->expectsOutputToContain('[download index Movies p10]')
            ->doesntExpectOutputToContain('[index ')
            ->assertSuccessful();
    });

    /*
    | 100 and 50 are fixture facts: the Movies walk covers index_movies_p1 (50
    | results) and index_movies_p2 (50), the Tv walk index_tv_p1 (50), each
    | terminated by the table-less catch-all page.
    */
    it('closes each category walk with the number of results it upserted', function (): void {
        // Arrange
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

        // Act & Assert
        $this->artisan('download:sync-index', ['--fresh' => true])
            ->expectsOutputToContain('  [download index Movies 100]')
            ->expectsOutputToContain('  [download index Tv 50]')
            ->assertSuccessful();
    });

    it('reports a zero total for a category whose first listing page is empty', function (): void {
        // Arrange
        $settings = resolve(DownloadSettings::class);
        $settings->uid = 'u123';
        $settings->pass = 'p123';
        $settings->save();
        Http::fake([
            '*' => Http::response(fixtureBytes('Download/downloads/index_movies_p1_no_table.html'), 200),
        ]);

        // Act & Assert
        $this->artisan('download:sync-index', ['--fresh' => true])
            ->expectsOutputToContain('  [download index Movies 0]')
            ->assertSuccessful();
    });
});
