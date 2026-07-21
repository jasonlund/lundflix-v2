<?php

declare(strict_types=1);

use App\Domains\Catalog\Models\Episode;
use App\Domains\Catalog\Models\Season;
use App\Domains\Catalog\Models\Show;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

it('casts season _tvdb_* attributes when fetched fresh from the database', function (): void {
    // Arrange
    $season = Season::factory()->create([
        '_tvdb_id' => 16824,
        '_tvdb_seriesId' => 121361,
        '_tvdb_type' => ['id' => 1, 'name' => 'Aired Order', 'type' => 'official'],
        '_tvdb_number' => 1,
        '_tvdb_imageType' => 6,
        'tvdb_synced_at' => now(),
    ]);

    // Act
    $fresh = Season::query()->findOrFail($season->id);

    // Assert
    expect($fresh->_tvdb_id)->toBeInt()
        ->and($fresh->_tvdb_seriesId)->toBeInt()
        ->and($fresh->_tvdb_number)->toBeInt()
        ->and($fresh->_tvdb_imageType)->toBeInt()
        ->and($fresh->_tvdb_type)->toBeArray()
        ->and($fresh->_tvdb_type['name'])->toBe('Aired Order')
        ->and($fresh->tvdb_synced_at)->toBeInstanceOf(Carbon::class);
});

it('casts episode _tvdb_* attributes when fetched fresh from the database', function (): void {
    // Arrange
    $episode = Episode::factory()->create([
        '_tvdb_id' => 5590847,
        '_tvdb_seriesId' => 121361,
        '_tvdb_runtime' => 60,
        '_tvdb_number' => 1,
        '_tvdb_absoluteNumber' => 1,
        '_tvdb_seasonNumber' => 1,
        '_tvdb_year' => 2011,
        '_tvdb_aired' => '2011-04-17',
        'tvdb_synced_at' => now(),
    ]);

    // Act
    $fresh = Episode::query()->findOrFail($episode->id);

    // Assert
    expect($fresh->_tvdb_id)->toBeInt()
        ->and($fresh->_tvdb_seriesId)->toBeInt()
        ->and($fresh->_tvdb_runtime)->toBeInt()
        ->and($fresh->_tvdb_number)->toBeInt()
        ->and($fresh->_tvdb_absoluteNumber)->toBeInt()
        ->and($fresh->_tvdb_seasonNumber)->toBeInt()
        ->and($fresh->_tvdb_year)->toBeInt()
        ->and($fresh->_tvdb_aired)->toBeInstanceOf(Carbon::class)
        ->and($fresh->tvdb_synced_at)->toBeInstanceOf(Carbon::class);
});

it('resolves season and episode relations to their parents', function (): void {
    // Arrange
    $show = Show::factory()->create();
    $season = Season::factory()->for($show)->create();
    Episode::factory()->count(2)->for($show)->for($season)->create();

    // Act
    $freshShow = Show::query()->findOrFail($show->id);

    // Assert
    expect($freshShow->seasons)->toBeInstanceOf(Collection::class)
        ->and($freshShow->seasons)->toHaveCount(1)
        ->and($freshShow->episodes)->toHaveCount(2)
        ->and($freshShow->seasons->first()->show->is($show))->toBeTrue()
        ->and($freshShow->seasons->first()->episodes)->toHaveCount(2)
        ->and($freshShow->episodes->first()->show->is($show))->toBeTrue()
        ->and($freshShow->episodes->first()->season->is($season))->toBeTrue();
});

it('casts the shows episode-tracking columns when fetched fresh', function (): void {
    // Arrange
    $show = Show::factory()->create([
        '_tvdb_defaultSeasonType' => 1,
        'episodes_synced_at' => now(),
    ]);

    // Act
    $fresh = Show::query()->findOrFail($show->id);

    // Assert
    expect($fresh->_tvdb_defaultSeasonType)->toBeInt()
        ->and($fresh->episodes_synced_at)->toBeInstanceOf(Carbon::class);
});
