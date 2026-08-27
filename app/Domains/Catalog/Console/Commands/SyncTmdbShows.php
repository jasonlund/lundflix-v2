<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Console\Commands;

use App\Domains\Catalog\Actions\ReconcileImdbOnlyShows;
use App\Domains\Catalog\Actions\UpsertTmdbImages;
use App\Domains\Catalog\Actions\UpsertTmdbShows;
use App\Domains\Catalog\Enums\SyncFeed;
use App\Domains\Catalog\Models\Show;
use App\Domains\Catalog\Services\TmdbApiService;
use App\Domains\Catalog\Support\SyncMarker;
use App\Domains\Catalog\Support\SyncWindow;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

#[Description('Two-phase TMDB show sync: hydrate our own shows by their resolvable id (direct _tmdb_id, or reconciled from _imdb_id via /find), then update-changed from the marker-derived changes window')]
#[Signature('catalog:sync-shows-tmdb {--fresh}')]
final class SyncTmdbShows extends TmdbSyncCommand
{
    private ReconcileImdbOnlyShows $reconcileImdbOnly;

    private UpsertTmdbShows $upsertShows;

    public function handle(
        TmdbApiService $api,
        ReconcileImdbOnlyShows $reconcileImdbOnly,
        UpsertTmdbShows $upsertShows,
        UpsertTmdbImages $upsertImages,
        SyncMarker $marker,
    ): int {
        $this->api = $api;
        $this->reconcileImdbOnly = $reconcileImdbOnly;
        $this->upsertShows = $upsertShows;
        $this->upsertImages = $upsertImages;

        // Run-start, not run-end: updates landing mid-run stay inside the next run's
        // overlap window rather than falling in the gap.
        $startedAt = CarbonImmutable::now();

        $this->output->writeln('Hydrating TMDB shows…');
        $insertFailed = $this->hydrateOwnShows();

        // --fresh already re-hydrated every candidate, so a changes pass is redundant.
        $changesFailed = false;

        if (! $this->option('fresh')) {
            $changesFailed = $this->updateChanged($marker);
        }

        // A failure means the window wasn't fully covered — the marker must not move
        // past a span still owed to the next run.
        if (! $insertFailed && ! $changesFailed) {
            $marker->advance($this->feed(), $startedAt);
        }

        return self::SUCCESS;
    }

    protected function feed(): SyncFeed
    {
        return SyncFeed::TmdbShows;
    }

    /**
     * @return Builder<Show>
     */
    protected function query(): Builder
    {
        return Show::query();
    }

    protected function entityLabel(): string
    {
        return 'shows';
    }

    protected function heartbeatTag(): string
    {
        return 'tmdb shows';
    }

    /**
     * @return iterable<int, int>
     */
    protected function changedIds(SyncWindow $window): iterable
    {
        return $this->api->changedTvIds($window->startDate(), $window->endDate());
    }

    /**
     * @param  array<int, int>  $ids
     * @return array<int, array<string, mixed>|null>
     */
    protected function hydrate(array $ids): array
    {
        return $this->api->tvShows($ids);
    }

    /**
     * @param  list<array<string, mixed>>  $payloads
     */
    protected function upsertPayloads(array $payloads): void
    {
        $this->upsertShows->handle($payloads);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function payloadTitle(array $payload): ?string
    {
        return $payload['name'] ?? null;
    }

    /**
     * Insert phase: hydrate OUR OWN shows carrying a resolvable id — `_tmdb_id`
     * hydrates directly, imdb-only resolves through /find first.
     */
    private function hydrateOwnShows(): bool
    {
        // chunkById specifically: the loop WRITES the columns it filters on (the
        // reconcile stamps _tmdb_id, hydration stamps tmdb_synced_at), and a --fresh
        // run spans the whole ~173k-row TVDB show universe.
        $query = Show::query()
            ->where(function ($query): void {
                $query->whereNotNull('_tmdb_id')->orWhereNotNull('_imdb_id');
            })
            ->unless($this->option('fresh'), function ($query): void {
                $query->whereNull('tmdb_synced_at');
            })
            ->select(['id', '_tmdb_id', '_imdb_id']);

        $failed = false;

        $query->chunkById(self::HYDRATE_SIZE, function (Collection $chunk) use (&$failed): void {
            $failed = $this->hydrateChunkSafely($chunk) || $failed;
        });

        return $failed;
    }

    /**
     * @param  Collection<int, Show>  $shows
     */
    private function hydrateChunkSafely(Collection $shows): bool
    {
        try {
            return $this->hydrateChunk($shows);
        } catch (\Throwable $e) {
            report($e);

            return true;
        }
    }

    /**
     * An unresolved imdb-only row (a /find miss) is NOT a failure — it stays
     * tmdb_synced_at-null and is retried every run regardless of the marker.
     *
     * @param  Collection<int, Show>  $shows
     */
    private function hydrateChunk(Collection $shows): bool
    {
        $directIds = $shows->whereNotNull('_tmdb_id')->pluck('_tmdb_id')->all();

        $resolvedIds = $this->reconcileImdbOnly->handle($shows, $this->api);

        $ids = array_values(array_unique(array_merge($directIds, $resolvedIds)));

        return $ids === [] ? false : $this->syncChunk($ids);
    }
}
