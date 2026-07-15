<?php

declare(strict_types=1);

use App\Domains\Download\Models\Download;
use App\Domains\Download\Settings\DownloadSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

/*
| The two feeds are faked with byte-exact captures of the real Movies (;72) and
| Tv (;73) mother-category RSS. _provider_id 7563849 is a Movies-feed item and
| 7563850 a Tv-feed item, so each id proves its own feed reached the mapper.
*/

it('ingests items from both mother-category feeds in one run', function (): void {
    // Arrange
    $settings = resolve(DownloadSettings::class);
    $settings->uid = 'u123';
    $settings->rss_key = 'rsskey123';
    $settings->save();
    Http::fake([
        '*;72' => Http::response(fixtureBytes('Download/downloads/rss_movies.xml'), 200),
        '*;73' => Http::response(fixtureBytes('Download/downloads/rss_tv.xml'), 200),
    ]);

    // Act
    $this->artisan('download:sync-rss')->assertSuccessful();

    // Assert
    $this->assertDatabaseHas('downloads', ['_provider_id' => 7563849]);
    $this->assertDatabaseHas('downloads', ['_provider_id' => 7563850]);
});

it('stamps each ingested row with its own feed category', function (): void {
    // Arrange
    $settings = resolve(DownloadSettings::class);
    $settings->uid = 'u123';
    $settings->rss_key = 'rsskey123';
    $settings->save();
    Http::fake([
        '*;72' => Http::response(fixtureBytes('Download/downloads/rss_movies.xml'), 200),
        '*;73' => Http::response(fixtureBytes('Download/downloads/rss_tv.xml'), 200),
    ]);

    // Act
    $this->artisan('download:sync-rss')->assertSuccessful();

    // Assert
    $this->assertDatabaseHas('downloads', ['_provider_id' => 7563849, '_provider_category' => '72']);
    $this->assertDatabaseHas('downloads', ['_provider_id' => 7563850, '_provider_category' => '73']);
});

it('stamps only the rss channel timestamp on an ingested row', function (): void {
    // Arrange
    $settings = resolve(DownloadSettings::class);
    $settings->uid = 'u123';
    $settings->rss_key = 'rsskey123';
    $settings->save();
    Http::fake([
        '*;72' => Http::response(fixtureBytes('Download/downloads/rss_movies.xml'), 200),
        '*;73' => Http::response(fixtureBytes('Download/downloads/rss_tv.xml'), 200),
    ]);

    // Act
    $this->artisan('download:sync-rss')->assertSuccessful();

    // Assert
    $row = Download::query()->where('_provider_id', 7563849)->firstOrFail();
    expect($row->rss_synced_at)->not->toBeNull();
    expect($row->index_synced_at)->toBeNull();
    expect($row->detail_synced_at)->toBeNull();
});

it('prints an intro and outro around the rss walk', function (): void {
    // Arrange
    $settings = resolve(DownloadSettings::class);
    $settings->uid = 'u123';
    $settings->rss_key = 'rsskey123';
    $settings->save();
    Http::fake([
        '*;72' => Http::response(fixtureBytes('Download/downloads/rss_movies.xml'), 200),
        '*;73' => Http::response(fixtureBytes('Download/downloads/rss_tv.xml'), 200),
    ]);

    // Act & Assert
    $this->artisan('download:sync-rss')
        ->expectsOutputToContain('Syncing download RSS…')
        ->expectsOutputToContain('Done.')
        ->assertSuccessful();
});
