<?php

declare(strict_types=1);

use App\Domains\Catalog\Actions\UpsertTvdbArtworks;
use App\Domains\Catalog\Actions\UpsertTvdbSeasons;
use App\Domains\Catalog\Actions\UpsertTvdbShows;
use App\Domains\Catalog\Console\Commands\TvdbShowsCommand;
use App\Domains\Catalog\Services\TvdbApiService;
use App\Domains\Catalog\Support\SyncIdsResult;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

/*
|--------------------------------------------------------------------------
| TvdbShowsCommand::syncIds() — contract pinned independent of its subclasses
|--------------------------------------------------------------------------
| syncIds() is protected on an abstract base, and whether it *collects* the
| failed ids has no CLI-observable effect — a suppressed id set and a genuinely
| clean run print identically. So $this->artisan() can't reach this contract;
| the seed/sync command Feature tests only pin it transitively, and only in the
| shape their own callers happen to use.
|
| This file pins it directly on a throwaway host that extends the base and
| implements its one abstract hook (ids()) as "return what I was constructed
| with", so a test drives an exact id set through the real pipeline. The host
| carries its own #[Signature] (Symfony refuses to construct a Command without
| one) under a name no real command uses, so it can't collide in the registry.
|
| The load-bearing case is failed = true with failedIds = []: the boolean is
| what SyncTvdbShows gates its marker on, and if suppressing collection also
| suppressed the failure signal the marker would advance past a window that
| failed, losing those updates permanently.
|
| Fixtures (byte-exact real TheTVDB v4 slices)
| tests/Fixtures/Catalog/tvdb/login.json — POST /login → data.token JWT; every
|   fake map answers it because Http::preventStrayRequests() is global and the
|   JWT is fetched (and cached) before any /series call.
| tests/Fixtures/Catalog/tvdb/series_extended.json — GET /series/{id}/extended
|   (wrapped {status,data}); the extended Breaking Bad payload, data.id 81189.
*/

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

beforeEach(function (): void {
    Cache::flush();
    config(['services.tvdb.key' => 'test-key']);
});

describe('syncIds() failure reporting', function (): void {
    it('reports no failure and no failed ids when every id hydrates', function (): void {
        // Arrange
        Http::fake([
            '*api4.thetvdb.com/v4/login*' => Http::response(fixtureBytes('Catalog/tvdb/login.json')),
            '*api4.thetvdb.com/v4/series/70327/extended*' => Http::response(fixtureBytes('Catalog/tvdb/series_extended.json')),
        ]);
        $host = new TvdbShowsCommandTestHost([70327], collects: false);

        // Act
        $result = $host->sync();

        // Assert
        expect($result->failed)->toBeFalse()
            ->and($result->failedIds)->toBe([]);
    });

    it('reports a per-id hydrate failure without listing the id when collection is off', function (): void {
        // Arrange
        // The load-bearing case: the 500 fails id 70327 through the pooled arm, and the
        // failure must still surface as the boolean even though the ids are suppressed.
        // Sleep is faked because the global retry middleware retries a 5xx.
        Sleep::fake();
        Exceptions::fake();
        Http::fake([
            '*api4.thetvdb.com/v4/login*' => Http::response(fixtureBytes('Catalog/tvdb/login.json')),
            '*api4.thetvdb.com/v4/series/70327/extended*' => Http::response('', 500),
        ]);
        $host = new TvdbShowsCommandTestHost([70327], collects: false);

        // Act
        $result = $host->sync();

        // Assert
        expect($result->failed)->toBeTrue()
            ->and($result->failedIds)->toBe([]);
    });

    it('lists the per-id hydrate failure when collection is on', function (): void {
        // Arrange
        Sleep::fake();
        Exceptions::fake();
        Http::fake([
            '*api4.thetvdb.com/v4/login*' => Http::response(fixtureBytes('Catalog/tvdb/login.json')),
            '*api4.thetvdb.com/v4/series/70327/extended*' => Http::response('', 500),
        ]);
        $host = new TvdbShowsCommandTestHost([70327], collects: true);

        // Act
        $result = $host->sync();

        // Assert
        expect($result->failed)->toBeTrue()
            ->and($result->failedIds)->toBe([70327]);
    });

    it('reports a chunk-level authentication failure without listing the chunk when collection is off', function (): void {
        // Arrange
        // A 401 forgets the JWT and throws out of the pool, so syncChunkSafely() fans the
        // WHOLE chunk in — the other failure arm, which must set the boolean too.
        Http::fake([
            '*api4.thetvdb.com/v4/login*' => Http::response(fixtureBytes('Catalog/tvdb/login.json')),
            '*api4.thetvdb.com/v4/series/*/extended*' => Http::response('', 401),
        ]);
        $host = new TvdbShowsCommandTestHost([70327, 81189, 121361], collects: false);

        // Act
        $result = $host->sync();

        // Assert
        expect($result->failed)->toBeTrue()
            ->and($result->failedIds)->toBe([]);
    });

    it('lists every id of the chunk on a chunk-level authentication failure when collection is on', function (): void {
        // Arrange
        Http::fake([
            '*api4.thetvdb.com/v4/login*' => Http::response(fixtureBytes('Catalog/tvdb/login.json')),
            '*api4.thetvdb.com/v4/series/*/extended*' => Http::response('', 401),
        ]);
        $host = new TvdbShowsCommandTestHost([70327, 81189, 121361], collects: true);

        // Act
        $result = $host->sync();

        // Assert
        expect($result->failed)->toBeTrue()
            ->and($result->failedIds)->toBe([70327, 81189, 121361]);
    });
});
