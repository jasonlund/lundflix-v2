<?php

declare(strict_types=1);

use App\Domains\Catalog\Data\PooledResult;
use App\Domains\Catalog\Exceptions\PooledIdFailed;
use App\Domains\Catalog\Services\Concerns\PoolsIdBatches;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| PoolsIdBatches trait — contract pinned independent of TMDB/TVDB
|--------------------------------------------------------------------------
| The trait's dedupe/chunk/order/aggregate-failure invariants are otherwise
| only pinned transitively through the TMDB and TVDB service Feature tests.
| This file pins the contract directly on a throwaway host that `use`s the
| trait and implements its abstract hooks (configure / poolConcurrency /
| resolvePooled / pooledFailure) with the minimal real-service semantics:
| a 404 decodes to null, a non-404 failed response signals a per-id
| PooledIdFailed, and a decodable 200 returns its raw body.
|
| The host binds an isolated base URL (a fixed const, not TMDB/TVDB) so its
| pooled requests can't collide with a real service pattern, and its
| aggregate failure is a plain RuntimeException naming the failed ids — the
| trait only needs *a* Throwable, so no service exception is imported.
|
| This Unit file boots the framework (Http facade) via TestCase and fakes
| every external call; Http::preventStrayRequests() is set locally since the
| global Feature beforeEach doesn't reach the Unit suite. Http::fake matches
| by URL, so each id gets a distinct /item/{id} pattern. Sleep is faked where
| a connection failure must exhaust retries so the base delay doesn't sleep.
*/

uses(TestCase::class)->beforeEach(function (): void {
    Http::preventStrayRequests();
});

/**
 * Throwaway host exercising the PoolsIdBatches contract in isolation. Mirrors
 * the real services' hooks minimally: 404 → null, non-404 failure → per-id
 * PooledIdFailed, decodable 200 → raw body. `pooled()` is private on the trait,
 * so `fetch()` exposes it for the test to call.
 */
final readonly class PoolsIdBatchesTestHost
{
    use PoolsIdBatches;

    private const string BASE_URL = 'https://pooled-host.test';

    public function __construct(private int $concurrency = 10) {}

    /**
     * @param  array<int, int|string>  $ids
     */
    public function fetch(array $ids): PooledResult
    {
        return $this->pooled($ids, fn (PendingRequest $request, int|string $id) => $request
            ->get("/item/{$id}"));
    }

    private function poolConcurrency(): int
    {
        return $this->concurrency;
    }

    private function configure(PendingRequest $request): PendingRequest
    {
        return $request->baseUrl(self::BASE_URL)->acceptJson();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolvePooled(Response $response): ?array
    {
        if ($response->notFound()) {
            return null;
        }

        if ($response->failed()) {
            throw new PooledIdFailed;
        }

        return $response->json();
    }

    /**
     * @param  array<int, int|string>  $failedIds
     */
    private function pooledFailure(array $failedIds): Throwable
    {
        return new RuntimeException('failed ids: '.implode(',', $failedIds));
    }
}

it('dedupes duplicate ids before fanning out, firing one request per unique id', function (): void {
    // Arrange
    Http::fake([
        '*/item/1*' => Http::response(['id' => 1]),
        '*/item/2*' => Http::response(['id' => 2]),
    ]);

    // Act
    resolve(PoolsIdBatchesTestHost::class)->fetch([1, 1, 2, 2, 1]);

    // Assert
    Http::assertSentCount(2);
});

it('chunks by poolConcurrency without dropping or reordering any id', function (): void {
    // Arrange
    // concurrency 3 over 7 ids fans out as chunks [1,2,3][4,5,6][7]; every id must
    // still fire exactly once and in input order across the chunk boundaries
    Http::fake(['*/item/*' => Http::response(['ok' => true])]);
    $host = new PoolsIdBatchesTestHost(concurrency: 3);

    // Act
    $host->fetch([1, 2, 3, 4, 5, 6, 7]);

    // Assert
    Http::assertSentCount(7);
    Http::assertSentInOrder([
        fn ($request): bool => str_contains((string) $request->url(), '/item/1'),
        fn ($request): bool => str_contains((string) $request->url(), '/item/2'),
        fn ($request): bool => str_contains((string) $request->url(), '/item/3'),
        fn ($request): bool => str_contains((string) $request->url(), '/item/4'),
        fn ($request): bool => str_contains((string) $request->url(), '/item/5'),
        fn ($request): bool => str_contains((string) $request->url(), '/item/6'),
        fn ($request): bool => str_contains((string) $request->url(), '/item/7'),
    ]);
});

it('decodes and returns results keyed in input order', function (): void {
    // Arrange
    // faked out of input order to prove the result follows the ids, not the pool
    Http::fake([
        '*/item/30*' => Http::response(['id' => 30]),
        '*/item/10*' => Http::response(['id' => 10]),
        '*/item/20*' => Http::response(['id' => 20]),
    ]);

    // Act
    $result = resolve(PoolsIdBatchesTestHost::class)->fetch([10, 20, 30]);

    // Assert
    expect(array_keys($result->results))->toBe([10, 20, 30])
        ->and($result->results[10])->toBe(['id' => 10])
        ->and($result->results[20])->toBe(['id' => 20])
        ->and($result->results[30])->toBe(['id' => 30])
        ->and($result->failedIds)->toBe([]);
});

it('returns the succeeding id and reports the aggregate when a non-Response pool entry lands a failed id', function (): void {
    // Arrange
    // a connection failure past retries lands a Throwable (not a Response) at the
    // pool slot; the loop must collect that id, not blow up dereferencing it
    Sleep::fake();
    Exceptions::fake();
    Http::fake([
        '*/item/1*' => fn () => throw new ConnectionException('Connection timed out'),
        '*/item/2*' => Http::response(['id' => 2]),
    ]);

    // Act
    $result = resolve(PoolsIdBatchesTestHost::class)->fetch([1, 2]);

    // Assert
    expect($result->results)->toBe([2 => ['id' => 2]])
        ->and($result->failedIds)->toBe([1]);
    Exceptions::assertReported(
        fn (RuntimeException $e): bool => str_contains($e->getMessage(), 'failed ids: 1')
    );
});

it('returns the succeeding id and reports the aggregate when resolvePooled signals a PooledIdFailed', function (): void {
    // Arrange
    // the 500 makes the host's resolvePooled throw PooledIdFailed for id 1; id 2
    // must still be decoded before the batch reports the aggregate failure
    Exceptions::fake();
    Http::fake([
        '*/item/1*' => Http::response('', 500),
        '*/item/2*' => Http::response(['id' => 2]),
    ]);

    // Act
    $result = resolve(PoolsIdBatchesTestHost::class)->fetch([1, 2]);

    // Assert
    expect($result->results)->toBe([2 => ['id' => 2]])
        ->and($result->failedIds)->toBe([1]);
    Exceptions::assertReported(
        fn (RuntimeException $e): bool => str_contains($e->getMessage(), 'failed ids: 1')
    );
});

it('sends Connection: close on pooled requests so each socket closes after its response', function (): void {
    // Arrange
    Http::fake(['*/item/*' => Http::response(['ok' => true])]);

    // Act
    resolve(PoolsIdBatchesTestHost::class)->fetch([1, 2]);

    // Assert
    Http::assertSent(fn ($request): bool => $request->hasHeader('Connection', 'close'));
});

it('returns the succeeding id and reports every failed id together in one aggregate pooledFailure, not short-circuiting on the first', function (): void {
    // Arrange
    // ids 1 and 3 both fail while 2 succeeds; the batch must evaluate all three,
    // return id 2, and report BOTH failed ids in a single aggregate, not stop at id 1
    Exceptions::fake();
    Http::fake([
        '*/item/1*' => Http::response('', 500),
        '*/item/2*' => Http::response(['id' => 2]),
        '*/item/3*' => Http::response('', 500),
    ]);

    // Act
    $result = resolve(PoolsIdBatchesTestHost::class)->fetch([1, 2, 3]);

    // Assert
    expect($result->results)->toBe([2 => ['id' => 2]]);
    Exceptions::assertReported(
        fn (RuntimeException $e): bool => str_contains($e->getMessage(), 'failed ids: 1,3')
    );
});
