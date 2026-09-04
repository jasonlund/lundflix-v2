<?php

declare(strict_types=1);

use App\Domains\Catalog\Actions\LinkTvdbEpisodeSeasons;
use App\Domains\Catalog\Models\Episode;
use App\Domains\Catalog\Models\Season;
use App\Domains\Catalog\Models\Show;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| LinkTvdbEpisodeSeasons re-derives a show's episode→season links from the
| show's CURRENT default-type seasons: an episode's raw `_tvdb_seasonNumber`
| is matched against the `_tvdb_number` of the show's seasons whose
| `_tvdb_type->id` is the show's `_tvdb_defaultSeasonType`, and any link that
| no longer resolves is cleared rather than left stale.
|
| No fixtures and no Http::fake here — the action never touches TheTVDB. It
| reads and writes only local rows, so the whole seam is the database: state
| goes in through the Catalog factories and the outcome is read back off the
| episodes. The upstream feed shapes that produce these rows are already
| covered where the crawl itself is faked (SeedTvdbEpisodesTest).
|--------------------------------------------------------------------------
*/

describe('handle() season link derivation', function (): void {
    it('links an episode to the default-type season carrying its season number', function (): void {
        // Arrange
        $show = Show::factory()->withTvdb()->create(['_tvdb_defaultSeasonType' => 1]);
        $season = Season::factory()->create([
            'show_id' => $show->id,
            '_tvdb_number' => 3,
            '_tvdb_type' => ['id' => 1, 'name' => 'Aired Order', 'type' => 'official'],
        ]);
        $episode = Episode::factory()->create([
            'show_id' => $show->id,
            'season_id' => null,
            '_tvdb_seasonNumber' => 3,
        ]);

        // Act
        resolve(LinkTvdbEpisodeSeasons::class)->handle($show);

        // Assert
        expect($episode->fresh()->season_id)->toBe($season->id);
    });
});

describe('handle() stale link clearing', function (): void {
    it('clears a link when no default-type season carries the episode season number', function (): void {
        // Models a season pulled from the feed: the show keeps season 3, but the
        // episode's season 4 no longer exists locally, so its link is now a
        // pointer to a grouping upstream has retracted.
        // Arrange
        $show = Show::factory()->withTvdb()->create(['_tvdb_defaultSeasonType' => 1]);
        $season = Season::factory()->create([
            'show_id' => $show->id,
            '_tvdb_number' => 3,
            '_tvdb_type' => ['id' => 1, 'name' => 'Aired Order', 'type' => 'official'],
        ]);
        $episode = Episode::factory()->create([
            'show_id' => $show->id,
            'season_id' => $season->id,
            '_tvdb_seasonNumber' => 4,
        ]);

        // Act
        resolve(LinkTvdbEpisodeSeasons::class)->handle($show);

        // Assert
        expect($episode->fresh()->season_id)->toBeNull();
    });

    it('clears a link when the show default season type has changed', function (): void {
        // Models the show switching ordering upstream: the link was derived under
        // aired order, and a season of the old type is no longer a season this
        // show orders its episodes by.
        // Arrange
        $show = Show::factory()->withTvdb()->create(['_tvdb_defaultSeasonType' => 2]);
        $season = Season::factory()->create([
            'show_id' => $show->id,
            '_tvdb_number' => 3,
            '_tvdb_type' => ['id' => 1, 'name' => 'Aired Order', 'type' => 'official'],
        ]);
        $episode = Episode::factory()->create([
            'show_id' => $show->id,
            'season_id' => $season->id,
            '_tvdb_seasonNumber' => 3,
        ]);

        // Act
        resolve(LinkTvdbEpisodeSeasons::class)->handle($show);

        // Assert
        expect($episode->fresh()->season_id)->toBeNull();
    });

    it('clears a link when the episode season number is now null', function (): void {
        // Models the feed dropping an episode's seasonNumber. There is no numbered
        // group left to re-derive it under, so a number-keyed pass alone would step
        // straight past the row and leave its old link standing.
        // Arrange
        $show = Show::factory()->withTvdb()->create(['_tvdb_defaultSeasonType' => 1]);
        $season = Season::factory()->create([
            'show_id' => $show->id,
            '_tvdb_number' => 3,
            '_tvdb_type' => ['id' => 1, 'name' => 'Aired Order', 'type' => 'official'],
        ]);
        $episode = Episode::factory()->create([
            'show_id' => $show->id,
            'season_id' => $season->id,
            '_tvdb_seasonNumber' => null,
        ]);

        // Act
        resolve(LinkTvdbEpisodeSeasons::class)->handle($show);

        // Assert
        expect($episode->fresh()->season_id)->toBeNull();
    });
});

describe('handle() null default season type', function (): void {
    it('leaves every existing link untouched when the show has no default season type', function (): void {
        // A null default names no ordering to resolve against, so it matches zero
        // seasons — re-deriving under it would wipe correct links wholesale rather
        // than fix any. Absent knowledge is not evidence the links are wrong.
        // Arrange
        $show = Show::factory()->withTvdb()->create(['_tvdb_defaultSeasonType' => null]);
        $season = Season::factory()->create([
            'show_id' => $show->id,
            '_tvdb_number' => 3,
            '_tvdb_type' => ['id' => 1, 'name' => 'Aired Order', 'type' => 'official'],
        ]);
        $episode = Episode::factory()->create([
            'show_id' => $show->id,
            'season_id' => $season->id,
            '_tvdb_seasonNumber' => 3,
        ]);

        // Act
        resolve(LinkTvdbEpisodeSeasons::class)->handle($show);

        // Assert
        expect($episode->fresh()->season_id)->toBe($season->id);
    });
});
