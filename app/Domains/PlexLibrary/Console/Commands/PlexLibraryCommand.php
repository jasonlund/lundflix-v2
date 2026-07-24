<?php

declare(strict_types=1);

namespace App\Domains\PlexLibrary\Console\Commands;

use App\Domains\PlexLibrary\Actions\ReconcilePlexEpisodes;
use App\Domains\PlexLibrary\Actions\ReconcilePlexLibraries;
use App\Domains\PlexLibrary\Actions\ReconcilePlexMovies;
use App\Domains\PlexLibrary\Actions\ReconcilePlexShows;
use App\Domains\PlexLibrary\Actions\UpsertPlexServer;
use App\Domains\PlexLibrary\Models\PlexLibrary;
use App\Domains\PlexLibrary\Models\PlexShow;
use App\Domains\PlexLibrary\Services\PlexLibraryService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Throwable;

abstract class PlexLibraryCommand extends Command
{
    public function __construct(
        private readonly PlexLibraryService $library,
        private readonly UpsertPlexServer $upsertServer,
        private readonly ReconcilePlexLibraries $reconcileLibraries,
        private readonly ReconcilePlexMovies $reconcileMovies,
        private readonly ReconcilePlexShows $reconcileShows,
        private readonly ReconcilePlexEpisodes $reconcileEpisodes,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->output->writeln('Connecting to Plex server…');
        $connection = $this->library->serverConnection();
        $uri = $connection['uri'];
        $token = $connection['accessToken'];

        $server = $this->upsertServer->handle($connection);

        $sections = $this->library->fetchSections($uri, $token);
        $libraryCount = $this->reconcileLibraries->handle($server, $sections);
        $this->output->writeln("  [libraries {$libraryCount}]");

        $libraries = PlexLibrary::query()
            ->where('plex_server_id', $server->id)
            ->get()
            ->groupBy('_plex_type');

        $movieTotal = 0;
        $lastBeat = -1;

        foreach ($libraries->get('movie', collect()) as $library) {
            $before = $movieTotal;
            $items = $this->library->fetchSectionItems($uri, $token, $library->_plex_key);
            $movieTotal += $this->reconcileMovies->handle($server, $library, $items);

            $lastBeat = $this->hundredBeat('movies', $before, $movieTotal, $lastBeat);
        }

        $this->flushTotal('movies', $movieTotal, $lastBeat);

        $showLibraries = $libraries->get('show', collect());

        $changed = [];
        $showTotal = 0;
        $lastBeat = -1;

        foreach ($showLibraries as $library) {
            $before = $showTotal;
            $items = $this->library->fetchSectionItems($uri, $token, $library->_plex_key);
            $showTotal += count($items);
            $changed = [...$changed, ...$this->reconcileShows->handle($server, $library, $items)];

            $lastBeat = $this->hundredBeat('shows', $before, $showTotal, $lastBeat);
        }

        $this->flushTotal('shows', $showTotal, $lastBeat);

        $failed = false;
        $episodeTotal = 0;
        $lastBeat = -1;

        // A single show's fetch failure is tolerated so one bad show doesn't sink
        // the whole crawl — mirrors SyncCatalog's report-and-continue posture.
        foreach ($this->showsToCrawl($showLibraries, $changed) as $show) {
            $before = $episodeTotal;

            try {
                $children = $this->library->fetchShowChildren($uri, $token, $show->_plex_ratingKey);
                $leaves = $this->library->fetchShowLeaves($uri, $token, $show->_plex_ratingKey);
                $episodeTotal += $this->reconcileEpisodes->handle($show, $children, $leaves);
            } catch (Throwable $e) {
                report($e);
                $failed = true;
            }

            $lastBeat = $this->hundredBeat('episodes', $before, $episodeTotal, $lastBeat);
        }

        $this->flushTotal('episodes', $episodeTotal, $lastBeat);

        $this->output->writeln('Done.');

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Emit a heartbeat at every multiple-of-100 the running total crosses in
     * this step (a single batch can cross several), printing the clean
     * boundary. Returns the last multiple emitted, or $lastBeat if none.
     */
    private function hundredBeat(string $label, int $before, int $after, int $lastBeat): int
    {
        for ($mark = intdiv($before, 100) * 100 + 100; $mark <= $after; $mark += 100) {
            $this->output->writeln("  [{$label} {$mark}]");
            $lastBeat = $mark;
        }

        return $lastBeat;
    }

    /**
     * Emit the final total once, unless the last heartbeat already reported it.
     */
    private function flushTotal(string $label, int $total, int $lastBeat): void
    {
        if ($total !== $lastBeat) {
            $this->output->writeln("  [{$label} {$total}]");
        }
    }

    /**
     * Select the shows whose episodes this command crawls — the single point of
     * variation between the full seed (every show in the show libraries) and the
     * incremental sync (only the changed set ReconcilePlexShows returned).
     *
     * @param  Collection<int, PlexLibrary>  $showLibraries
     * @param  list<array{_plex_ratingKey: string, id: int}>  $changed
     * @return Collection<int, PlexShow>
     */
    abstract protected function showsToCrawl(Collection $showLibraries, array $changed): Collection;
}
