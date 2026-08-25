<?php

declare(strict_types=1);

use App\Domains\Catalog\Actions\UpsertTvdbSeasons;
use App\Domains\Catalog\Models\Season;
use App\Domains\Catalog\Models\Show;

// Fixture: tests/Fixtures/Catalog/tvdb/series_extended.json — a byte-exact real
// TheTVDB /series/{id}/extended capture (Breaking Bad, _tvdb_id 81189).
// ['data']['seasons'] is a 13-entry list; the first is _tvdb_id 30272 with
// number 1, imageType 7, and a nested `type` object {id:1,name:"Aired Order",…}.

describe('handle() tvdb season upsert', function (): void {
    it('persists each season raw with show_id', function (): void {
        // Arrange
        $show = Show::factory()->create();
        $seasons = json_decode(fixtureBytes('Catalog/tvdb/series_extended.json'), true)['data']['seasons'];

        // Act
        (new UpsertTvdbSeasons)->handle($show, $seasons);

        // Assert
        $this->assertDatabaseHas('seasons', [
            '_tvdb_id' => 30272,
            'show_id' => $show->id,
            '_tvdb_number' => 1,
            '_tvdb_imageType' => 7,
        ]);
        $season = Season::where('_tvdb_id', 30272)->firstOrFail();
        expect($season->_tvdb_type['id'])->toBe(1);
        $this->assertDatabaseCount('seasons', 13);
    });

    it('stores _tvdb_type raw as array', function (): void {
        // Arrange
        $show = Show::factory()->create();
        $seasons = json_decode(fixtureBytes('Catalog/tvdb/series_extended.json'), true)['data']['seasons'];

        // Act
        (new UpsertTvdbSeasons)->handle($show, $seasons);

        // Assert
        $season = Season::where('_tvdb_id', 30272)->firstOrFail();
        expect($season->_tvdb_type['name'])->toBe('Aired Order')
            ->and($season->_tvdb_type['type'])->toBe('official');
    });

    it('stamps tvdb_synced_at and returns count', function (): void {
        // Arrange
        $show = Show::factory()->create();
        $seasons = json_decode(fixtureBytes('Catalog/tvdb/series_extended.json'), true)['data']['seasons'];

        // Act
        $count = (new UpsertTvdbSeasons)->handle($show, $seasons);

        // Assert
        expect($count)->toBe(13);
        expect(Season::whereNull('tvdb_synced_at')->count())->toBe(0);
        expect(Season::whereNotNull('tvdb_synced_at')->count())->toBe(13);
    });

    it('does not duplicate seasons on re-run', function (): void {
        // Arrange
        $show = Show::factory()->create();
        $seasons = json_decode(fixtureBytes('Catalog/tvdb/series_extended.json'), true)['data']['seasons'];

        // Act
        $action = new UpsertTvdbSeasons;
        $action->handle($show, $seasons);
        $action->handle($show, $seasons);

        // Assert
        $this->assertDatabaseCount('seasons', 13);
    });

    it('skips a season whose _tvdb_id normalizes to null', function (): void {
        // Arrange
        $show = Show::factory()->create();
        $seasons = json_decode(fixtureBytes('Catalog/tvdb/series_extended.json'), true)['data']['seasons'];
        $seasons[0]['id'] = 99999999999999; // oversized id SourceId::positiveInt() rejects

        // Act
        (new UpsertTvdbSeasons)->handle($show, $seasons);

        // Assert
        expect(Season::where('_tvdb_id', 99999999999999)->exists())->toBeFalse();
        $this->assertDatabaseCount('seasons', 12);
    });

    it('returns only the accepted count, not the raw input count, when a row is skipped', function (): void {
        // Arrange
        $show = Show::factory()->create();
        $seasons = [
            ['id' => 30272, 'seriesId' => 81189, 'number' => 1],
            ['id' => '1335814-slug', 'seriesId' => 81189, 'number' => 2],
        ];

        // Act
        $count = (new UpsertTvdbSeasons)->handle($show, $seasons);

        // Assert
        expect($count)->toBe(1);
        $this->assertDatabaseCount('seasons', 1);
    });

    it('returns 0 and persists nothing for empty seasons', function (): void {
        // Arrange
        $show = Show::factory()->create();

        // Act
        $count = (new UpsertTvdbSeasons)->handle($show, []);

        // Assert
        expect($count)->toBe(0);
        $this->assertDatabaseCount('seasons', 0);
    });
});
