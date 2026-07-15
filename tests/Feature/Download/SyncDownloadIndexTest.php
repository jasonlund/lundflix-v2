<?php

declare(strict_types=1);

use App\Domains\Download\Enums\Category;
use App\Domains\Download\Models\Download;
use App\Domains\Download\Services\DownloadService;
use App\Domains\Download\Settings\DownloadSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

/*
| The two index category walks are faked with byte-exact captures of the real
| Movies (72) and Tv (73) listing pages. index_movies_p1 advertises a later
| lastPage so the walk continues; p2's 50 ids are disjoint from p1's, so
| _provider_id 7563723 proves page 2 was fetched and persisted. The catch-all
| stub returns a table-less page (0 results) that terminates each walk after
| its last real page.
*/

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
    Http::assertSent(fn ($request): bool => str_contains((string) $request->url(), '72=&p=2'));
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
    $page1Ids = resolve(DownloadService::class)->index(Category::Movies)->results->pluck('downloadId');
    $page1Ids->each(fn (int $id) => Download::factory()->create(['_provider_id' => $id]));

    // Act
    $this->artisan('download:sync-index')->assertSuccessful();

    // Assert
    Http::assertNotSent(fn ($request): bool => str_contains((string) $request->url(), '72=&p=2'));
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
    Http::assertSent(fn ($request): bool => str_contains((string) $request->url(), '72=&p=2'));
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

it('prints a category-labeled heartbeat every 10th page walked', function (): void {
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
        ->expectsOutputToContain('[index Movies p10]')
        ->assertSuccessful();
});
