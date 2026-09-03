<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Console\Commands;

use App\Domains\Catalog\Actions\ReindexTouchedRows;
use App\Domains\Catalog\Actions\UpsertTmdbImages;
use App\Domains\Catalog\Actions\UpsertTmdbMovies;
use App\Domains\Catalog\Data\SyncWindow;
use App\Domains\Catalog\Enums\SyncFeed;
use App\Domains\Catalog\Models\Movie;
use App\Domains\Catalog\Services\TmdbApiService;
use App\Domains\Catalog\Support\SyncMarker;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Database\Eloquent\Builder;

#[Description('Incremental TMDB movie sync: one marker-windowed pass over the changes feed, refreshing the titles the catalog holds and inserting the ones it does not')]
#[Signature('catalog:sync-movies')]
class SyncTmdbMovies extends TmdbSyncCommand
{
    private UpsertTmdbMovies $upsertMovies;

    public function handle(
        TmdbApiService $api,
        UpsertTmdbMovies $upsertMovies,
        UpsertTmdbImages $upsertImages,
        SyncMarker $marker,
        ReindexTouchedRows $reindexTouchedRows,
    ): int {
        $this->api = $api;
        $this->upsertMovies = $upsertMovies;
        $this->upsertImages = $upsertImages;
        $this->reindexTouchedRows = $reindexTouchedRows;

        // The changes feed is the leg's only source, so its one pass is the whole
        // ingest — a full-catalog rescan is catalog:seed-movies' job, not a schedule's.
        return $this->runLeg($marker, fn (): bool => $this->updateChanged($marker));
    }

    protected function feed(): SyncFeed
    {
        return SyncFeed::TmdbMovies;
    }

    /**
     * @return Builder<Movie>
     */
    protected function query(): Builder
    {
        return Movie::query();
    }

    protected function entityLabel(): string
    {
        return 'movies';
    }

    protected function heartbeatTag(): string
    {
        return 'tmdb movies';
    }

    /**
     * TMDB owns a movie's identity outright, so a changed id we don't hold is a
     * title to create rather than one to skip.
     */
    #[\Override]
    protected function insertHeartbeatTag(): ?string
    {
        return 'new tmdb movies';
    }

    /**
     * @return iterable<int, int>
     */
    protected function changedIds(SyncWindow $window): iterable
    {
        return $this->api->changedMovieIds($window->startDate(), $window->endDate());
    }

    /**
     * @param  array<int, int>  $ids
     * @return array<int, array<string, mixed>|null>
     */
    protected function hydrate(array $ids): array
    {
        return $this->api->movies($ids);
    }

    /**
     * A `video:true` TMDB record is a promo/trailer, not a real film. It stays
     * present-as-key in the results, so dropping it here never reads as a fetch
     * failure.
     *
     * @param  array<int, array<string, mixed>|null>  $results
     * @return list<array<string, mixed>>
     */
    #[\Override]
    protected function payloads(array $results): array
    {
        return array_values(array_filter(
            $results,
            static fn (?array $payload): bool => $payload !== null && empty($payload['video']),
        ));
    }

    /**
     * @param  list<array<string, mixed>>  $payloads
     */
    protected function upsertPayloads(array $payloads): void
    {
        $this->upsertMovies->handle($payloads);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function payloadTitle(array $payload): ?string
    {
        return $payload['title'] ?? null;
    }
}
