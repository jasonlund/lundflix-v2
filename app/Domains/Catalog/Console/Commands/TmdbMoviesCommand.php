<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Console\Commands;

use App\Domains\Catalog\Actions\StampGoneMovies;
use App\Domains\Catalog\Actions\UpsertTmdbMovies;
use App\Domains\Catalog\Enums\SyncFeed;
use App\Domains\Catalog\Models\Movie;
use Illuminate\Database\Eloquent\Builder;
use Override;

/**
 * Everything the two movie legs share — the marker feed, the table, the changes
 * source and the hydrate/upsert calls — so the incremental sync and the export
 * seed differ only in which ingest phase they run.
 *
 * Feed-driven insert stays out: `insertHeartbeatTag()` is opted into by
 * `SyncTmdbMovies` alone, and the seed leg must keep the base's null default or
 * `closeRun()` flushes an insert total it has no way to produce.
 */
abstract class TmdbMoviesCommand extends TmdbSyncCommand
{
    protected UpsertTmdbMovies $upsertMovies;

    protected StampGoneMovies $stampGoneMovies;

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
     * @return iterable<int, int>
     */
    protected function changedIds(string $day): iterable
    {
        return $this->api->changedMovieIds($day);
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

    /**
     * The movies leg's opt-in of the base's no-op seam: `movies` carries a
     * tmdb_gone_at column, so this is the one leg with somewhere to put the
     * answer. {@see StampGoneMovies} owns what the two writes mean.
     *
     * @param  list<int>  $goneIds
     * @param  list<int>  $presentIds
     */
    #[Override]
    protected function recordGoneIds(array $goneIds, array $presentIds): void
    {
        $this->stampGoneMovies->handle(collect($goneIds), collect($presentIds));
    }
}
