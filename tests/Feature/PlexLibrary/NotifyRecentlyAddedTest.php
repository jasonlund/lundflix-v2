<?php

declare(strict_types=1);

use App\Domains\Catalog\Models\Movie;
use App\Domains\Catalog\Models\Show;
use App\Domains\PlexLibrary\Actions\NotifyRecentlyAdded;
use App\Domains\PlexLibrary\Models\PlexEpisode;
use App\Domains\PlexLibrary\Models\PlexMovie;
use App\Domains\PlexLibrary\Models\PlexSeason;
use App\Domains\PlexLibrary\Models\PlexShow;
use App\Domains\PlexLibrary\Notifications\RecentlyAddedToPlex;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

/*
 * The action takes no ids: it asks SelectRipeAnnouncements what is ripe, announces
 * exactly that, and stamps those rows announced so the next run can't repeat them.
 *
 * Ripeness is therefore arranged the way SelectRipeAnnouncementsTest arranges it —
 * an explicit `created_at` per row measured against windows set in each Arrange
 * (120s movies / 300s episodes / 900s deadline), never by travelling the clock and
 * never by relying on the shipped defaults.
 *
 * The digest lines are the ones RecentlyAddedDigest already renders for these two
 * rows: a catalog-matched movie ('Blade Runner 2049 (2017)') and one episode of a
 * ten-episode season ('Severance S02E04').
 */

it('sends one notification whose lines cover every ripe movie and show in the same run', function (): void {
    // Arrange
    Notification::fake();
    config()->set('services.slack.notifications.channel', '#lundflix');
    config()->set('services.plex.announce.movie_debounce_seconds', 120);
    config()->set('services.plex.announce.episode_debounce_seconds', 300);
    config()->set('services.plex.announce.hard_deadline_seconds', 900);
    movieAddedToPlex(secondsAgo: 300);
    episodeAddedToPlex(secondsAgo: 600);

    // Act
    resolve(NotifyRecentlyAdded::class)->handle();

    // Assert
    Notification::assertSentOnDemand(
        RecentlyAddedToPlex::class,
        fn (RecentlyAddedToPlex $notification, array $channels, AnonymousNotifiable $notifiable): bool => $notifiable->routeNotificationFor('slack') === config('services.slack.notifications.channel')
            && $notification->lines === ['Blade Runner 2049 (2017)', 'Severance S02E04'],
    );
    Notification::assertCount(1);
});

it('stamps announced_at on exactly the rows it announced', function (): void {
    // Arrange
    Notification::fake();
    config()->set('services.slack.notifications.channel', '#lundflix');
    config()->set('services.plex.announce.movie_debounce_seconds', 120);
    config()->set('services.plex.announce.episode_debounce_seconds', 300);
    config()->set('services.plex.announce.hard_deadline_seconds', 900);
    $plexMovie = movieAddedToPlex(secondsAgo: 300);
    $plexEpisode = episodeAddedToPlex(secondsAgo: 600);

    // Act
    resolve(NotifyRecentlyAdded::class)->handle();

    // Assert
    expect($plexMovie->fresh()->announced_at)->not->toBeNull();
    expect($plexEpisode->fresh()->announced_at)->not->toBeNull();
});

// Movies and episodes ripen on separate clocks, so one run can announce the quiet
// kind while the other is still filling: the fresh episode must survive that run
// pending, or it is lost — nothing ever announces it again.
it('leaves rows that are not yet ripe unstamped and unannounced', function (): void {
    // Arrange
    Notification::fake();
    config()->set('services.slack.notifications.channel', '#lundflix');
    config()->set('services.plex.announce.movie_debounce_seconds', 120);
    config()->set('services.plex.announce.episode_debounce_seconds', 300);
    config()->set('services.plex.announce.hard_deadline_seconds', 900);
    movieAddedToPlex(secondsAgo: 300);
    $freshEpisode = episodeAddedToPlex(secondsAgo: 10);

    // Act
    resolve(NotifyRecentlyAdded::class)->handle();

    // Assert
    expect($freshEpisode->fresh()->announced_at)->toBeNull();
    Notification::assertNotSentTo(
        new AnonymousNotifiable,
        RecentlyAddedToPlex::class,
        fn (RecentlyAddedToPlex $notification): bool => in_array('Severance S02E04', $notification->lines, true),
    );
});

it('sends nothing when nothing is ripe', function (): void {
    // Arrange
    Notification::fake();
    config()->set('services.slack.notifications.channel', '#lundflix');
    config()->set('services.plex.announce.movie_debounce_seconds', 120);
    config()->set('services.plex.announce.hard_deadline_seconds', 900);
    movieAddedToPlex(secondsAgo: 10);

    // Act
    resolve(NotifyRecentlyAdded::class)->handle();

    // Assert
    Notification::assertNothingSent();
});

// An unconfigured channel must not burn the pending state: stamping a row nobody
// was told about would silently drop it the moment Slack is configured.
it('sends nothing and stamps nothing when no Slack channel is configured', function (): void {
    // Arrange
    Notification::fake();
    config()->set('services.slack.notifications.channel');
    config()->set('services.plex.announce.movie_debounce_seconds', 120);
    config()->set('services.plex.announce.hard_deadline_seconds', 900);
    $plexMovie = movieAddedToPlex(secondsAgo: 300);

    // Act
    resolve(NotifyRecentlyAdded::class)->handle();

    // Assert
    Notification::assertNothingSent();
    expect($plexMovie->fresh()->announced_at)->toBeNull();
});

// Queueing is what keeps a Slack outage from failing the sync, and it needs its own
// case: Notification::fake() short-circuits *before* the queue, so no assertSentOnDemand
// test above can see it, and asserting `instanceof ShouldQueue` would test structure
// rather than behavior — hence the dedicated Queue::fake().
it('queues the send rather than delivering it inline', function (): void {
    // Arrange
    Queue::fake();
    config()->set('services.slack.notifications.channel', '#lundflix');
    config()->set('services.plex.announce.movie_debounce_seconds', 120);
    config()->set('services.plex.announce.hard_deadline_seconds', 900);
    movieAddedToPlex(secondsAgo: 300);

    // Act
    resolve(NotifyRecentlyAdded::class)->handle();

    // Assert
    Queue::assertPushed(
        SendQueuedNotifications::class,
        fn (SendQueuedNotifications $job): bool => $job->notification instanceof RecentlyAddedToPlex,
    );
});

/**
 * A pending movie that arrived $secondsAgo and that the catalog already knows,
 * digesting to 'Blade Runner 2049 (2017)'.
 */
function movieAddedToPlex(int $secondsAgo): PlexMovie
{
    Movie::factory()->create([
        '_tmdb_id' => 335984,
        '_tmdb_title' => 'Blade Runner 2049',
        '_tmdb_release_date' => '2017-10-04',
    ]);

    return PlexMovie::factory()->create([
        '_tmdb_id' => 335984,
        '_plex_title' => 'blade.runner.2049.2017.2160p',
        '_plex_year' => 2016,
        'created_at' => now()->subSeconds($secondsAgo),
        'announced_at' => null,
    ]);
}

/**
 * A pending episode that arrived $secondsAgo — the fourth of a ten-episode season
 * of a catalog-matched show, digesting to 'Severance S02E04'.
 */
function episodeAddedToPlex(int $secondsAgo): PlexEpisode
{
    Show::factory()->withTvdb()->create([
        '_tvdb_id' => 371980,
        '_tvdb_name' => 'Severance',
    ]);
    $plexShow = PlexShow::factory()->create([
        '_tvdb_id' => 371980,
        '_plex_title' => 'severance.2022.1080p',
    ]);
    $season = PlexSeason::factory()->create([
        'plex_show_id' => $plexShow->id,
        '_plex_index' => 2,
        '_plex_leafCount' => 10,
    ]);

    return PlexEpisode::factory()->create([
        'plex_season_id' => $season->id,
        '_plex_parentIndex' => 2,
        '_plex_index' => 4,
        'created_at' => now()->subSeconds($secondsAgo),
        'announced_at' => null,
    ]);
}
