<?php

declare(strict_types=1);

use App\Domains\Catalog\Data\UnitRef;
use App\Domains\Catalog\Models\Episode;
use App\Domains\Catalog\Models\Movie;
use App\Domains\Catalog\Models\Show;
use App\Domains\PlexLibrary\Actions\NotifyRecentlyAdded;
use App\Domains\PlexLibrary\Data\ArrivedTitle;
use App\Domains\PlexLibrary\Events\UnitsArrived;
use App\Domains\PlexLibrary\Models\PlexEpisode;
use App\Domains\PlexLibrary\Models\PlexMovie;
use App\Domains\PlexLibrary\Models\PlexSeason;
use App\Domains\PlexLibrary\Models\PlexShow;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;

/*
 * What PlexLibrary publishes when arrivals become ready: one UnitsArrived event naming
 * each affected catalog title, its arrived units and a display name.
 *
 * Readiness is arranged the way NotifyRecentlyAddedTest arranges it — an explicit
 * `created_at` measured against windows set in the beforeEach (120s movies / 300s
 * episodes / 900s deadline), never by travelling the clock. Every mirror row here
 * arrived 600s ago, past every window.
 *
 * No Slack channel is configured, so no digest is posted; the digest is
 * NotifyRecentlyAddedTest's concern.
 */

/**
 * An arrival old enough that every debounce window set in the beforeEach has closed.
 */
function readyMirrorArrival(): Carbon
{
    return now()->subSeconds(600);
}

/**
 * Each published title flattened to plain values, its units as naturally sorted
 * `kind:id` keys — so a payload compares by value, never by UnitRef identity nor by
 * the order the units were collected in.
 *
 * @return list<array{type: string, id: int, name: string, units: list<string>}>
 */
function publishedArrivalTitles(UnitsArrived $event): array
{
    return collect($event->titles)
        ->map(fn (ArrivedTitle $title): array => [
            'type' => $title->titleType,
            'id' => $title->titleId,
            'name' => $title->name,
            'units' => collect($title->units)
                ->map(fn (UnitRef $unit): string => "{$unit->kind->value}:{$unit->id}")
                ->sort(strnatcmp(...))
                ->values()
                ->all(),
        ])
        ->values()
        ->all();
}

beforeEach(function (): void {
    Event::fake([UnitsArrived::class]);
    Notification::fake();
    config()->set('services.plex.announce.movie_debounce_seconds', 120);
    config()->set('services.plex.announce.episode_debounce_seconds', 300);
    config()->set('services.plex.announce.hard_deadline_seconds', 900);
});

describe('handle() published titles', function (): void {
    it('publishes a ready movie matched to the catalog as a movie title carrying its one unit and catalog name', function (): void {
        // Arrange
        $movie = Movie::factory()->create([
            '_tmdb_id' => 335984,
            '_tmdb_title' => 'Blade Runner 2049',
        ]);
        PlexMovie::factory()->create([
            '_tmdb_id' => 335984,
            '_plex_title' => 'blade.runner.2049.2017.2160p',
            'created_at' => readyMirrorArrival(),
            'announced_at' => null,
        ]);

        // Act
        resolve(NotifyRecentlyAdded::class)->handle();

        // Assert
        Event::assertDispatched(
            UnitsArrived::class,
            fn (UnitsArrived $event): bool => publishedArrivalTitles($event) === [
                ['type' => 'movie', 'id' => $movie->id, 'name' => 'Blade Runner 2049', 'units' => ["movie:{$movie->id}"]],
            ],
        );
    });

    it("publishes a show's ready episodes under the show as one title carrying every arrived episode", function (): void {
        // Arrange
        $show = Show::factory()->withTvdb()->create([
            '_tvdb_id' => 371980,
            '_tvdb_name' => 'Severance',
        ]);
        $fourth = Episode::factory()->create(['show_id' => $show->id, '_tvdb_id' => 9_000_204, '_tvdb_seasonNumber' => 2, '_tvdb_number' => 4]);
        $fifth = Episode::factory()->create(['show_id' => $show->id, '_tvdb_id' => 9_000_205, '_tvdb_seasonNumber' => 2, '_tvdb_number' => 5]);
        // Catalogued but never mirrored, so it must not ride along with its siblings.
        Episode::factory()->create(['show_id' => $show->id, '_tvdb_id' => 9_000_206, '_tvdb_seasonNumber' => 2, '_tvdb_number' => 6]);
        $plexShow = PlexShow::factory()->create(['_tvdb_id' => 371980, '_plex_title' => 'severance.2022.1080p']);
        $season = PlexSeason::factory()->create(['plex_show_id' => $plexShow->id, '_plex_index' => 2]);
        PlexEpisode::factory()->create([
            'plex_season_id' => $season->id,
            '_tvdb_id' => 9_000_204,
            '_plex_parentIndex' => 2,
            '_plex_index' => 4,
            'created_at' => readyMirrorArrival(),
            'announced_at' => null,
        ]);
        PlexEpisode::factory()->create([
            'plex_season_id' => $season->id,
            '_tvdb_id' => 9_000_205,
            '_plex_parentIndex' => 2,
            '_plex_index' => 5,
            'created_at' => readyMirrorArrival(),
            'announced_at' => null,
        ]);

        // Act
        resolve(NotifyRecentlyAdded::class)->handle();

        // Assert
        Event::assertDispatched(
            UnitsArrived::class,
            fn (UnitsArrived $event): bool => publishedArrivalTitles($event) === [
                ['type' => 'show', 'id' => $show->id, 'name' => 'Severance', 'units' => ["episode:{$fourth->id}", "episode:{$fifth->id}"]],
            ],
        );
    });

    it('matches an episode whose mirror row has no TVDB id by its season and episode position', function (): void {
        // Arrange
        $show = Show::factory()->withTvdb()->create([
            '_tvdb_id' => 371980,
            '_tvdb_name' => 'Severance',
        ]);
        $fourth = Episode::factory()->create(['show_id' => $show->id, '_tvdb_seasonNumber' => 2, '_tvdb_number' => 4]);
        // Same season, next position: only the episode number tells the two apart.
        Episode::factory()->create(['show_id' => $show->id, '_tvdb_seasonNumber' => 2, '_tvdb_number' => 5]);
        $plexShow = PlexShow::factory()->create(['_tvdb_id' => 371980, '_plex_title' => 'severance.2022.1080p']);
        $season = PlexSeason::factory()->create(['plex_show_id' => $plexShow->id, '_plex_index' => 2]);
        PlexEpisode::factory()->create([
            'plex_season_id' => $season->id,
            '_tvdb_id' => null,
            '_plex_parentIndex' => 2,
            '_plex_index' => 4,
            'created_at' => readyMirrorArrival(),
            'announced_at' => null,
        ]);

        // Act
        resolve(NotifyRecentlyAdded::class)->handle();

        // Assert
        Event::assertDispatched(
            UnitsArrived::class,
            fn (UnitsArrived $event): bool => publishedArrivalTitles($event) === [
                ['type' => 'show', 'id' => $show->id, 'name' => 'Severance', 'units' => ["episode:{$fourth->id}"]],
            ],
        );
    });

    it("publishes a title with no catalog name under Plex's own title", function (): void {
        // Arrange
        // Matched on the crosswalk id alone: no withTmdb(), so the catalog row carries no name.
        $movie = Movie::factory()->create(['_tmdb_id' => 335984]);
        PlexMovie::factory()->create([
            '_tmdb_id' => 335984,
            '_plex_title' => 'blade.runner.2049.2017.2160p',
            'created_at' => readyMirrorArrival(),
            'announced_at' => null,
        ]);

        // Act
        resolve(NotifyRecentlyAdded::class)->handle();

        // Assert
        Event::assertDispatched(
            UnitsArrived::class,
            fn (UnitsArrived $event): bool => publishedArrivalTitles($event) === [
                ['type' => 'movie', 'id' => $movie->id, 'name' => 'blade.runner.2049.2017.2160p', 'units' => ["movie:{$movie->id}"]],
            ],
        );
    });

    it('publishes a unit held by two mirror rows once', function (): void {
        // Arrange
        $movie = Movie::factory()->create([
            '_tmdb_id' => 335984,
            '_tmdb_title' => 'Blade Runner 2049',
        ]);
        // Two copies of one film (say a 4K and a 1080p library), each its own mirror row.
        PlexMovie::factory()->count(2)->create([
            '_tmdb_id' => 335984,
            'created_at' => readyMirrorArrival(),
            'announced_at' => null,
        ]);

        // Act
        resolve(NotifyRecentlyAdded::class)->handle();

        // Assert
        // Counted as well as matched: one event per mirror row would satisfy the payload
        // predicate on each dispatch and still publish the unit twice.
        Event::assertDispatchedTimes(UnitsArrived::class, 1);
        Event::assertDispatched(
            UnitsArrived::class,
            fn (UnitsArrived $event): bool => publishedArrivalTitles($event) === [
                ['type' => 'movie', 'id' => $movie->id, 'name' => 'Blade Runner 2049', 'units' => ["movie:{$movie->id}"]],
            ],
        );
    });
});

describe('handle() unmatched mirror rows', function (): void {
    // A row the catalog cannot name has no title to publish under, but it was still
    // announced in the digest, so it is stamped like any other and never revisited.
    it('stamps a mirror row with no catalog match but publishes nothing', function (): void {
        // Arrange
        $plexMovie = PlexMovie::factory()->create([
            '_tmdb_id' => 999_999,
            'created_at' => readyMirrorArrival(),
            'announced_at' => null,
        ]);

        // Act
        resolve(NotifyRecentlyAdded::class)->handle();

        // Assert
        expect($plexMovie->fresh()->announced_at)->not->toBeNull();
        Event::assertNotDispatched(UnitsArrived::class);
    });
});
