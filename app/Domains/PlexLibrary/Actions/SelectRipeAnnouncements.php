<?php

declare(strict_types=1);

namespace App\Domains\PlexLibrary\Actions;

use App\Domains\PlexLibrary\Data\RipeAnnouncements;
use App\Domains\PlexLibrary\Models\PlexEpisode;
use App\Domains\PlexLibrary\Models\PlexMovie;
use App\Domains\PlexLibrary\Support\DebounceWindow;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

final class SelectRipeAnnouncements
{
    public function handle(): RipeAnnouncements
    {
        return new RipeAnnouncements($this->ripeMovieIds(), $this->ripeEpisodeIds());
    }

    /**
     * @return list<int>
     */
    private function ripeMovieIds(): array
    {
        $pending = PlexMovie::query()
            ->whereNull('announced_at')
            ->get(['id', 'created_at']);

        $quietSeconds = (int) config('services.plex.announce.movie_debounce_seconds');

        return $this->bucketIsRipe($pending, $quietSeconds)
            ? $pending->pluck('id')->all()
            : [];
    }

    /**
     * @return list<int>
     */
    private function ripeEpisodeIds(): array
    {
        $pending = PlexEpisode::query()
            ->whereNull('announced_at')
            ->get(['id', 'plex_show_id', 'created_at']);

        $quietSeconds = (int) config('services.plex.announce.episode_debounce_seconds');

        // Each show debounces on its own arrivals: a show still receiving episodes
        // must not hold back an unrelated show that has already gone quiet.
        return $pending
            ->groupBy('plex_show_id')
            ->filter(fn (Collection $episodes): bool => $this->bucketIsRipe($episodes, $quietSeconds))
            ->flatMap(fn (Collection $episodes): Collection => $episodes->pluck('id'))
            ->values()
            ->all();
    }

    /**
     * Ripeness belongs to the whole bucket, never to a single row: once a bucket
     * ripens every row in it ships, not only the rows that individually aged out —
     * a library still dripping in arrivals would otherwise never announce.
     *
     * The hard deadline is read here because it is one clock shared by every
     * bucket; only the quiet window differs per caller.
     *
     * @param  Collection<int, PlexEpisode|PlexMovie>  $bucket
     */
    private function bucketIsRipe(Collection $bucket, int $quietSeconds): bool
    {
        if ($bucket->isEmpty()) {
            return false;
        }

        // Ripeness is decided in PHP, never in SQL: sqlite and MySQL disagree on
        // datetime comparison, and the arithmetic belongs to DebounceWindow.
        $arrivals = $bucket->pluck('created_at');

        /** @var CarbonInterface $oldest */
        $oldest = $arrivals->min();
        /** @var CarbonInterface $newest */
        $newest = $arrivals->max();

        return DebounceWindow::isRipe(
            $oldest,
            $newest,
            $quietSeconds,
            (int) config('services.plex.announce.hard_deadline_seconds'),
        );
    }
}
