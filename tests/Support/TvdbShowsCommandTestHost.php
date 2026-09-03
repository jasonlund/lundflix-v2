<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domains\Catalog\Actions\UpsertTvdbArtworks;
use App\Domains\Catalog\Actions\UpsertTvdbSeasons;
use App\Domains\Catalog\Actions\UpsertTvdbShows;
use App\Domains\Catalog\Console\Commands\TvdbShowsCommand;
use App\Domains\Catalog\Data\SyncIdsResult;
use App\Domains\Catalog\Services\TvdbApiService;
use Illuminate\Console\Attributes\Signature;
use Override;

/**
 * Throwaway host exercising the syncIds() contract in isolation: it feeds a
 * fixed id set into the real pipeline and exposes the protected syncIds()
 * through sync(). Id sets are kept tiny on purpose — syncChunk() dereferences
 * $this->output on every 1000th hydrated payload, which a Command built outside
 * the console kernel doesn't have.
 */
#[Signature('catalog:tvdb-shows-command-test-host')]
final class TvdbShowsCommandTestHost extends TvdbShowsCommand
{
    /**
     * @param  list<int>  $seriesIds
     */
    public function __construct(private readonly array $seriesIds, private readonly bool $collects)
    {
        parent::__construct();
    }

    public function sync(): SyncIdsResult
    {
        // Resolved from the container, not doubled: the actions are final readonly,
        // so the tests drive them for real against Http::fake() + RefreshDatabase.
        $api = resolve(TvdbApiService::class);

        return $this->syncIds(
            $this->ids($api),
            $api,
            resolve(UpsertTvdbShows::class),
            resolve(UpsertTvdbArtworks::class),
            resolve(UpsertTvdbSeasons::class),
        );
    }

    /**
     * @return list<int>
     */
    protected function ids(TvdbApiService $api): iterable
    {
        return $this->seriesIds;
    }

    #[Override]
    protected function collectsFailedIds(): bool
    {
        return $this->collects;
    }
}
