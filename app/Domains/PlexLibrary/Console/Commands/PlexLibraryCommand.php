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
use App\Domains\PlexLibrary\Models\PlexServer;
use App\Domains\PlexLibrary\Models\PlexShow;
use App\Domains\PlexLibrary\Services\PlexLibraryService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Enumerable;
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
        $this->output->writeln('Connecting to Plex server…');
        $connection = $this->library->serverConnection();
        $uri = $connection->uri;
        $token = $connection->accessToken;

        $server = $this->upsertServer->handle($connection);

        $sections = $this->library->fetchSections($uri, $token);
        $libraryCount = $this->reconcileLibraries->handle($server, $sections);
        $this->output->writeln("  [libraries {$libraryCount}]");

        $libraries = PlexLibrary::query()
            ->where('plex_server_id', $server->id)
            ->get()
            ->groupBy('_plex_type');

        $this->reconcileTopLevel($server, $uri, $token, $libraries->get('movie', collect()), $this->reconcileMovies, 'movies');

        $showLibraries = $libraries->get('show', collect());

        $this->reconcileTopLevel($server, $uri, $token, $showLibraries, $this->reconcileShows, 'shows');

        $failed = false;
        $episodeTotal = 0;
        $lastBeat = -1;

        // A single show's fetch failure is tolerated so one bad show doesn't sink
        // the whole crawl — mirrors SyncCatalog's report-and-continue posture.
        foreach ($this->showsToCrawl($showLibraries) as $show) {
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
            // This server's backlog is backfill, not news, and the server scope is
            // the whole guard: handle() reconciles one server, so a sibling's
            // pending rows are somebody else's news and stamping them would drop
            // them for good (ripe-announcement selection only ever sees
            // announced_at IS NULL). Deliberately not bounded to the rows this run
            // inserted: a seed that crashed mid-crawl and was retried leaves its
            // own rows with an older arrival time, and any such bound strands them
            // pending for the next sync to announce as the whole back catalogue.
            // Accepted in exchange: a seed run against a live mirror can stamp a
            // row a concurrent sync left inside its debounce window — the seed is
            // premised on running once against a fresh mirror.
            PlexMovie::query()
                ->where('plex_server_id', $server->id)
                ->whereNull('announced_at')
                ->update(['announced_at' => now()]);

            PlexEpisode::query()
                ->where('plex_server_id', $server->id)
                ->whereNull('announced_at')
                ->update(['announced_at' => now()]);
        }

        $this->output->writeln('Done.');

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Walk one library type's sections page by page — upsert each page, then
     * sweep the library — heartbeating the running total as it goes.
     *
     * The sweep sits outside any catch by design: a page fetch that throws
     * propagates, so a half-read library never authorizes it.
     *
     * @param  Collection<int, PlexLibrary>  $libraries
     */
    private function reconcileTopLevel(
        PlexServer $server,
        string $uri,
        string $token,
        Collection $libraries,
        ReconcilePlexMovies|ReconcilePlexShows $reconciler,
        string $label,
    ): void {
        $total = 0;
        $lastBeat = -1;

        foreach ($libraries as $library) {
            // One clock per library, shared by every page: the sweep below deletes
            // whatever this pass didn't stamp, so a per-page $now would leave page
            // 1 behind its own library's watermark and delete it.
            $now = now();

            foreach ($this->library->fetchSectionItems($uri, $token, $library->_plex_key) as $page) {
                $before = $total;
                $total += $reconciler->upsertPage($server, $library, $page, $now);

                // Beats per page, not per library: a production section walks for
                // hours, and a beat deferred to the end of the library is silence
                // followed by one burst.
                $lastBeat = $this->hundredBeat($label, $before, $total, $lastBeat);
            }

            $reconciler->prune($server, $library, $now);
        }

        $this->flushTotal($label, $total, $lastBeat);
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
     * The shows whose episodes this command crawls: narrow rows carrying only the
     * columns the crawl reads and writes, drawn from the show libraries and
     * narrowed by the subclass's {@see constrainCrawl()} predicate.
     *
     * @param  Collection<int, PlexLibrary>  $showLibraries
     * @return Enumerable<int, PlexShow>
     */
    private function showsToCrawl(Collection $showLibraries): Enumerable
    {
        $query = PlexShow::query()
            ->whereIn('plex_library_id', $showLibraries->pluck('id'))
            ->select(['id', '_plex_ratingKey', 'plex_server_id']);

        $this->constrainCrawl($query);

        // lazyById, never cursor()/lazy(): the crawl writes episodes_synced_at
        // to the very rows it walks, and only PK pagination cannot skip or
        // double-process a row when a non-key column mutates mid-iteration.
        return $query->lazyById(500);
    }

    /**
     * Narrow the crawl set — the single point of variation between the full seed
     * (every show in the show libraries) and the incremental sync (only the shows
     * whose episode watermark is behind).
     *
     * @param  Builder<PlexShow>  $query
     */
    abstract protected function constrainCrawl(Builder $query): void;

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
