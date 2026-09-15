<?php

declare(strict_types=1);

namespace App\Domains\PlexLibrary\Actions;

use App\Domains\Catalog\Data\UnitRef;
use App\Domains\Catalog\Enums\UnitKind;
use App\Domains\Catalog\Models\Episode;
use App\Domains\Catalog\Models\Movie;
use App\Domains\Catalog\Models\Show;
use App\Domains\PlexLibrary\Data\ArrivedTitle;
use App\Domains\PlexLibrary\Data\RipeAnnouncements;
use App\Domains\PlexLibrary\Events\UnitsArrived;
use App\Domains\PlexLibrary\Models\PlexEpisode;
use App\Domains\PlexLibrary\Models\PlexMovie;
use App\Domains\PlexLibrary\Notifications\RecentlyAddedToPlex;
use App\Domains\PlexLibrary\Support\MirrorMatch;
use App\Domains\PlexLibrary\Support\RecentlyAddedDigest;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use stdClass;

final readonly class NotifyRecentlyAdded
{
    public function __construct(private SelectRipeAnnouncements $selectRipe) {}

    /**
     * Returns the catalog units published, not the mirror rows ripened: an unmatched
     * row publishes nothing, and two copies of one unit publish it once.
     */
    public function handle(): int
    {
        $ripe = $this->selectRipe->handle();

        if ($ripe->movieIds === [] && $ripe->episodeIds === []) {
            return 0;
        }

        // Stamped before the send, never after: the notification is queued, and a run
        // 60s later would re-announce every row whose job is still in flight.
        $published = $this->publish($ripe);

        // announced_at means published, not posted: with no channel configured the
        // arrivals still publish and stamp, and Slack simply never hears of them.
        $channel = config('services.slack.notifications.channel');

        if (filled($channel)) {
            $this->postDigest($ripe, $channel);
        }

        return $published;
    }

    /**
     * Both stamps cover the one digest, so a half-applied pair would strand the stamped
     * half — selection only ever revisits rows whose announced_at is still null. The
     * event is dispatched inside the transaction so its listeners' writes commit with
     * the stamp: a throwing listener rolls the stamp back and the next run retries.
     */
    private function publish(RipeAnnouncements $ripe): int
    {
        $titles = $this->arrivedTitles($ripe->movieIds, $ripe->episodeIds);

        DB::transaction(function () use ($ripe, $titles): void {
            PlexMovie::query()->whereIn('id', $ripe->movieIds)->update(['announced_at' => now()]);
            PlexEpisode::query()->whereIn('id', $ripe->episodeIds)->update(['announced_at' => now()]);

            if ($titles !== []) {
                event(new UnitsArrived($titles));
            }
        });

        return collect($titles)->sum(fn (ArrivedTitle $title): int => count($title->units));
    }

    /**
     * Called only after the stamp commits: the default sync queue makes notify() an
     * inline Slack call, and no rollback can recall a dispatched announcement.
     */
    private function postDigest(RipeAnnouncements $ripe, string $channel): void
    {
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

        Notification::route('slack', $channel)->notify(new RecentlyAddedToPlex($lines));
    }

    /**
     * Rows the catalog cannot match have no title to publish under, so they drop out
     * here; two mirror rows of one unit (a 4K and a 1080p copy) collapse to one unit.
     *
     * @param  list<int>  $movieIds
     * @param  list<int>  $episodeIds
     * @return list<ArrivedTitle>
     */
    private function arrivedTitles(array $movieIds, array $episodeIds): array
    {
        return $this->groupIntoTitles($this->movieMatches($movieIds), (new Movie)->getMorphClass(), UnitKind::Movie)
            ->concat($this->groupIntoTitles($this->episodeMatches($episodeIds), (new Show)->getMorphClass(), UnitKind::Episode))
            ->all();
    }

    /**
     * @param  list<int>  $plexMovieIds
     * @return Collection<int, stdClass>
     */
    private function movieMatches(array $plexMovieIds): Collection
    {
        return Movie::query()
            ->join('plex_movies', MirrorMatch::movie(...))
            ->whereIn('plex_movies.id', $plexMovieIds)
            ->toBase()
            ->get([
                'movies.id as title_id',
                'movies.id as unit_id',
                'movies._tmdb_title as catalog_name',
                'plex_movies._plex_title as plex_name',
            ]);
    }

    /**
     * @param  list<int>  $plexEpisodeIds
     * @return Collection<int, stdClass>
     */
    private function episodeMatches(array $plexEpisodeIds): Collection
    {
        // The mirror show is aliased because the positional match opens its own
        // plex_shows scope, which would otherwise shadow this one.
        return Episode::query()
            ->join('shows', 'shows.id', '=', 'episodes.show_id')
            ->join('plex_episodes', MirrorMatch::episode(...))
            ->join('plex_shows as mirror_shows', 'mirror_shows.id', '=', 'plex_episodes.plex_show_id')
            ->whereIn('plex_episodes.id', $plexEpisodeIds)
            ->toBase()
            ->get([
                'shows.id as title_id',
                'episodes.id as unit_id',
                'shows._tvdb_name as catalog_name',
                'mirror_shows._plex_title as plex_name',
            ]);
    }

    /**
     * @param  Collection<int, stdClass>  $matches
     * @return Collection<int, ArrivedTitle>
     */
    private function groupIntoTitles(Collection $matches, string $titleType, UnitKind $unitKind): Collection
    {
        return $matches
            ->groupBy('title_id')
            ->map(function (Collection $titleMatches) use ($titleType, $unitKind): ArrivedTitle {
                $first = $titleMatches->first();

                return new ArrivedTitle(
                    $titleType,
                    (int) $first->title_id,
                    RecentlyAddedDigest::displayName($first->catalog_name, $first->plex_name),
                    $titleMatches
                        ->map(fn (stdClass $match): int => (int) $match->unit_id)
                        ->unique()
                        ->map(fn (int $unitId): UnitRef => new UnitRef($unitKind, $unitId))
                        ->values()
                        ->all(),
                );
            })
            ->values();
    }
}
