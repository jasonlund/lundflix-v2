<?php

declare(strict_types=1);

namespace App\Domains\PlexLibrary\Actions;

use App\Domains\PlexLibrary\Models\PlexEpisode;
use App\Domains\PlexLibrary\Models\PlexMovie;
use App\Domains\PlexLibrary\Notifications\RecentlyAddedToPlex;
use App\Domains\PlexLibrary\Support\RecentlyAddedDigest;
use Illuminate\Support\Facades\Notification;

final readonly class NotifyRecentlyAdded
{
    public function __construct(private SelectRipeAnnouncements $selectRipe) {}

    public function handle(): void
    {
        $ripe = $this->selectRipe->handle();

        if ($ripe->movieIds === [] && $ripe->episodeIds === []) {
            return;
        }

        $channel = config('services.slack.notifications.channel');

        // An unconfigured channel returns before anything is stamped: marking rows
        // announced that nobody was told about would drop them once Slack is wired up.
        if (blank($channel)) {
            return;
        }

        // The eager loads are load-bearing, not an optimization: the digest reads the
        // catalog match and the season's episode total off every row, so without them
        // each line costs its own queries as it renders.
        $movies = PlexMovie::query()
            ->whereIn('id', $ripe->movieIds)
            ->with('movie')
            ->get();

        $episodes = PlexEpisode::query()
            ->whereIn('id', $ripe->episodeIds)
            ->with('plexShow.show', 'plexSeason')
            ->get();

        // Lines are rendered here, at dispatch, so the notification carries plain
        // strings: a queued job holding models would re-resolve them at send time.
        $lines = RecentlyAddedDigest::lines($movies, $episodes);

        // Stamped before the send, never after: the notification is queued, and a run
        // 60s later would re-announce every row whose job is still in flight.
        PlexMovie::query()->whereIn('id', $ripe->movieIds)->update(['announced_at' => now()]);
        PlexEpisode::query()->whereIn('id', $ripe->episodeIds)->update(['announced_at' => now()]);

        Notification::route('slack', $channel)->notify(new RecentlyAddedToPlex($lines));
    }
}
