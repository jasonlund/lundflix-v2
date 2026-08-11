<?php

declare(strict_types=1);

namespace App\Domains\PlexLibrary\Console\Commands;

use App\Domains\PlexLibrary\Actions\NotifyRecentlyAdded;
use App\Domains\PlexLibrary\Actions\ReconcilePlexEpisodes;
use App\Domains\PlexLibrary\Actions\ReconcilePlexLibraries;
use App\Domains\PlexLibrary\Actions\ReconcilePlexMovies;
use App\Domains\PlexLibrary\Actions\ReconcilePlexShows;
use App\Domains\PlexLibrary\Actions\UpsertPlexServer;
use App\Domains\PlexLibrary\Models\PlexEpisode;
use App\Domains\PlexLibrary\Models\PlexLibrary;
use App\Domains\PlexLibrary\Models\PlexMovie;
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
        private readonly NotifyRecentlyAdded $notifyRecentlyAdded,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $startedAt = now();

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

                // Stamped only once the show's episodes actually landed. The show's
                // own _plex_updatedAt was already written by ReconcilePlexShows, so
                // this separate watermark is the only thing that can tell the next
                // sync a failed show still owes a crawl.
                $show->update(['episodes_synced_at' => now()]);
            } catch (Throwable $e) {
                report($e);
                $failed = true;
            }

            $lastBeat = $this->hundredBeat('episodes', $before, $episodeTotal, $lastBeat);
        }

        $this->flushTotal('episodes', $episodeTotal, $lastBeat);

        if ($this->notifiesRecentlyAdded()) {
            $this->notifyRecentlyAdded->handle();
        } else {
            // This run's own rows are backfill, not news — and only its own: the
            // scope clauses keep the stamp off a sibling server's backlog and off
            // an arrival a concurrent sync left pending inside its debounce
            // window. Either would be dropped for good, since ripe-announcement
            // selection only ever sees announced_at IS NULL. created_at is the
            // arrival clock (upsert writes it on insert only), so bounding by the
            // run's start says "what this run inserted" without plumbing ids.
            PlexMovie::query()
                ->where('plex_server_id', $server->id)
                ->where('created_at', '>=', $startedAt)
                ->whereNull('announced_at')
                ->update(['announced_at' => now()]);

            PlexEpisode::query()
                ->where('plex_server_id', $server->id)
                ->where('created_at', '>=', $startedAt)
                ->whereNull('announced_at')
                ->update(['announced_at' => now()]);
        }

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

    /**
     * Whether this command announces the arrivals still awaiting announcement.
     * Only a run that discovers arrivals may say yes: the incremental sync inserts
     * what just landed, while a
     * full seed inserts the entire existing library — announcing there would blast
     * the whole mirror into Slack, and against an empty database that is every
     * title Plex holds.
     *
     * Answering no is not passive silence: the run stamps every still-pending row
     * as announced, so the backlog it wrote can never surface as a later sync's
     * news.
     *
     * Abstract rather than a defaulted hook so a future subcommand has to state its
     * own answer instead of silently inheriting one.
     */
    abstract protected function notifiesRecentlyAdded(): bool;
}
