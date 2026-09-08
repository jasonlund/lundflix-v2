<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Console\Commands;

use App\Domains\Catalog\Actions\ReindexTouchedRows;
use App\Domains\Catalog\Actions\UpsertTmdbImages;
use App\Domains\Catalog\Actions\UpsertTmdbMovies;
use App\Domains\Catalog\Services\TmdbApiService;
use App\Domains\Catalog\Support\SyncMarker;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;

#[Description('Incremental TMDB movie sync: one marker-windowed pass over the changes feed, refreshing the titles the catalog holds and inserting the ones it does not')]
#[Signature('catalog:sync-movies')]
final class SyncTmdbMovies extends TmdbMoviesCommand
{
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

    /**
     * TMDB owns a movie's identity outright, so a changed id we don't hold is a
     * title to create rather than one to skip.
     */
    #[\Override]
    protected function insertHeartbeatTag(): ?string
    {
        return 'new tmdb movies';
    }
}
