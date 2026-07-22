<?php

declare(strict_types=1);

use App\Domains\Catalog\Actions\UpsertTvdbEpisodes;
use App\Domains\Catalog\Models\Episode;
use App\Domains\Catalog\Models\Show;

// Fixture: tests/Fixtures/Catalog/tvdb/series_episodes_page1.json — a byte-exact
// real TheTVDB /series/{id}/episodes capture (The Simpsons, seriesId 71663).
// ['data']['episodes'] is a 3-entry list; the first is _tvdb_id 4350173, name
// "Good Night", aired 1987-04-19, seasonNumber 0, number 1.

it('persists each episode raw with show_id', function (): void {
    // Arrange
    $show = Show::factory()->create();
    $episodes = json_decode(fixtureBytes('Catalog/tvdb/series_episodes_page1.json'), true)['data']['episodes'];

    // Act
    (new UpsertTvdbEpisodes)->handle($show, $episodes);

    // Assert
    $this->assertDatabaseHas('episodes', [
        '_tvdb_id' => 4350173,
        'show_id' => $show->id,
        '_tvdb_name' => 'Good Night',
        '_tvdb_seasonNumber' => 0,
        '_tvdb_number' => 1,
    ]);
    $episode = Episode::where('_tvdb_id', 4350173)->firstOrFail();
    expect($episode->_tvdb_aired->format('Y-m-d'))->toBe('1987-04-19');
    $this->assertDatabaseCount('episodes', 3);
});

it('stamps tvdb_synced_at and returns count', function (): void {
    // Arrange
    $show = Show::factory()->create();
    $episodes = json_decode(fixtureBytes('Catalog/tvdb/series_episodes_page1.json'), true)['data']['episodes'];

    // Act
    $count = (new UpsertTvdbEpisodes)->handle($show, $episodes);

    // Assert
    expect($count)->toBe(3);
    expect(Episode::whereNotNull('tvdb_synced_at')->count())->toBe(3);
});

it('does not duplicate episodes on re-run', function (): void {
    // Arrange
    $show = Show::factory()->create();
    $episodes = json_decode(fixtureBytes('Catalog/tvdb/series_episodes_page1.json'), true)['data']['episodes'];

    // Act
    $action = new UpsertTvdbEpisodes;
    $action->handle($show, $episodes);
    $action->handle($show, $episodes);

    // Assert
    $this->assertDatabaseCount('episodes', 3);
});

it('skips an episode whose _tvdb_id normalizes to null', function (): void {
    // Arrange
    $show = Show::factory()->create();
    $episodes = json_decode(fixtureBytes('Catalog/tvdb/series_episodes_page1.json'), true)['data']['episodes'];
    $episodes[0]['id'] = 99999999999999; // oversized id SourceId::positiveInt() rejects

    // Act
    (new UpsertTvdbEpisodes)->handle($show, $episodes);

    // Assert
    expect(Episode::where('_tvdb_id', 99999999999999)->exists())->toBeFalse();
    $this->assertDatabaseCount('episodes', 2);
});

it('returns 0 and persists nothing for empty episodes', function (): void {
    // Arrange
    $show = Show::factory()->create();

    // Act
    $count = (new UpsertTvdbEpisodes)->handle($show, []);

    // Assert
    expect($count)->toBe(0);
    $this->assertDatabaseCount('episodes', 0);
});
