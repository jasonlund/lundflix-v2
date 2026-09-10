<?php

declare(strict_types=1);

use App\Domains\Catalog\Data\PooledResult;
use App\Domains\Catalog\Services\PooledTransport;
use Carbon\CarbonInterval;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Tests\Support\PoolsIdBatchesTestHost;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| PoolsIdBatches trait — contract pinned independent of TMDB/TVDB
|--------------------------------------------------------------------------
| The trait's dedupe/order/aggregate-failure invariants are otherwise
| only pinned transitively through the TMDB and TVDB service Feature tests.
| This file pins the contract directly on Tests\Support\PoolsIdBatchesTestHost,
| a throwaway host that `use`s the trait and implements its abstract hooks
| (configure / poolConcurrency / resolvePooled / pooledFailure) with the minimal
| real-service semantics: a 404 decodes to null, a non-404 failed response
| signals a per-id PooledIdFailed, and a decodable 200 returns its raw body.
|
| This Unit file boots the framework (Http facade) via TestCase and fakes
| every external call; Http::preventStrayRequests() is set locally since the
| global Feature beforeEach doesn't reach the Unit suite. Http::fake matches
| by URL, so each id gets a distinct /item/{id} pattern. Sleep is faked where
| a connection failure must exhaust retries so the base delay doesn't sleep.
|
| The pacing group reads the durations back off Sleep::whenFakingSleep() rather
| than Sleep::assertSequence(), because what it pins is a relationship between
| waits (the step between them, one rate against another) and not a list of
| literals. A faked sleep does not advance the wall clock the pacer measures
| against, so a wait carries the whole backlog of the waits nobody really took:
| the spacing under test is the STEP between consecutive waits, and only the
| first wait is the bare token interval.
|
| Attempt counts for a connection-level failure are taken from a counter the
| fake closure increments, not from Http::recorded(): a stub that throws never
| completes an exchange, so the recorder sees nothing for exactly the attempts
| under test. Statuses/connection failures here are synthetic on purpose —
| transient transport errors can't be captured as real-data fixtures.
*/

uses(TestCase::class)->beforeEach(function (): void {
    Http::preventStrayRequests();
});

describe('pooled() fan-out and ordering', function (): void {
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

    it('dispatches every id exactly once and in input order at concurrency 3', function (): void {
        // Arrange
        // one rolling window has no boundary between batches to enforce ordering on
        // its behalf, so concurrency 3 over 7 ids must still fire each id exactly
        // once, in input order
        Http::fake(['*/item/*' => Http::response(['ok' => true])]);
        $host = new PoolsIdBatchesTestHost(concurrency: 3);

        // Act
        $host->fetch([1, 2, 3, 4, 5, 6, 7]);

        // Assert
        Http::assertSentCount(7);
        Http::assertSentInOrder([
            fn ($request): bool => Str::contains((string) $request->url(), '/item/1'),
            fn ($request): bool => Str::contains((string) $request->url(), '/item/2'),
            fn ($request): bool => Str::contains((string) $request->url(), '/item/3'),
            fn ($request): bool => Str::contains((string) $request->url(), '/item/4'),
            fn ($request): bool => Str::contains((string) $request->url(), '/item/5'),
            fn ($request): bool => Str::contains((string) $request->url(), '/item/6'),
            fn ($request): bool => Str::contains((string) $request->url(), '/item/7'),
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
});

describe('pooled() per-id failure handling', function (): void {
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
            fn (RuntimeException $e): bool => Str::contains($e->getMessage(), 'failed ids: 1')
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
            fn (RuntimeException $e): bool => Str::contains($e->getMessage(), 'failed ids: 1')
        );
    });
});

describe('pooled() request transport', function (): void {
    it('negotiates HTTP/2 on every pooled request', function (): void {
        // Arrange
        Http::fake(['*/item/*' => Http::response(['ok' => true])]);

        // Act
        resolve(PoolsIdBatchesTestHost::class)->fetch([1, 2]);

        // Assert
        // read off the PSR request Guzzle built, which is where the protocol version
        // is fixed — before any handler (including the fake) can see the request
        $versions = Http::recorded()
            ->map(fn (array $exchange): string => $exchange[0]->toPsrRequest()->getProtocolVersion())
            ->all();
        expect($versions)->toBe(['2.0', '2.0']);
    });

    it('sends no Connection header on pooled requests', function (): void {
        // Arrange
        Http::fake(['*/item/*' => Http::response(['ok' => true])]);

        // Act
        resolve(PoolsIdBatchesTestHost::class)->fetch([1, 2]);

        // Assert
        // Connection: close tore down the socket after every response — the whole
        // cost the shared transport exists to stop paying, so no request may carry it
        $connectionHeaders = Http::recorded()
            ->flatMap(fn (array $exchange): array => $exchange[0]->header('Connection'))
            ->all();
        expect($connectionHeaders)->toBe([]);
    });
});

describe('pooled() shared transport', function (): void {
    it('dispatches two separate hosts through one shared transport instance', function (): void {
        // Arrange
        // two independent hosts on purpose: the tally only sums when both pooled()
        // calls resolve the SAME transport, so a per-resolve binding hands the
        // assertion a third, empty instance instead
        Http::fake(['*/item/*' => Http::response(['ok' => true])]);
        $first = new PoolsIdBatchesTestHost;
        $second = new PoolsIdBatchesTestHost;
        $fetch = fn (PoolsIdBatchesTestHost $host, array $ids): PooledResult => $host->fetch($ids);

        // Act
        array_map($fetch, [$first, $second], [[1, 2, 3], [4, 5]]);

        // Assert
        expect(resolve(PooledTransport::class)->stats()->dispatched)->toBe(5);
    });
});

describe('pooled() multi-id failure aggregation', function (): void {
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
            fn (RuntimeException $e): bool => Str::contains($e->getMessage(), 'failed ids: 1,3')
        );
    });
});

describe('pooled() bounded retry', function (): void {
    it('re-attempts an id that keeps failing at the connection level exactly three times', function (): void {
        // Arrange
        // the closure counts its own invocations: a stub that throws completes no
        // exchange, so Http::recorded() stays empty for precisely these attempts
        Sleep::fake();
        Exceptions::fake();
        $attempts = 0;
        Http::fake(['*/item/1*' => function () use (&$attempts): never {
            $attempts++;

            throw new ConnectionException('Connection timed out');
        }]);

        // Act
        resolve(PoolsIdBatchesTestHost::class)->fetch([1]);

        // Assert
        expect($attempts)->toBe(3);
    });

    it('returns the result of an id that succeeds on its second attempt, reporting nothing', function (): void {
        // Arrange
        // the first attempt fails at the connection level and the re-queued second
        // succeeds, so the id must read as a plain success — not a failure that
        // happened to be retried
        Sleep::fake();
        Exceptions::fake();
        $attempts = 0;
        Http::fake(['*/item/1*' => function () use (&$attempts) {
            $attempts++;

            if ($attempts === 1) {
                throw new ConnectionException('Connection timed out');
            }

            return Http::response(['id' => 1]);
        }]);

        // Act
        $result = resolve(PoolsIdBatchesTestHost::class)->fetch([1]);

        // Assert
        expect($attempts)->toBe(2)
            ->and($result->results)->toBe([1 => ['id' => 1]])
            ->and($result->failedIds)->toBe([]);
        Exceptions::assertNothingReported();
    });

    it('collects an id that exhausts its attempts into failedIds under one aggregate report', function (): void {
        // Arrange
        // three attempts must still produce a single aggregate for the batch, not one
        // report per attempt, and id 2 must be unaffected by its sibling's re-queues
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
        Exceptions::assertReportedCount(1);
        Exceptions::assertReported(
            fn (RuntimeException $e): bool => Str::contains($e->getMessage(), 'failed ids: 1')
        );
    });

    it('does not let the global retry middleware multiply a pooled request past three attempts', function (): void {
        // Arrange
        // the middleware retries a 5xx with a blocking usleep, which on this path would
        // stall every other stream on the shared connection; the transport owns retry
        // here instead, so its three dispatches must reach the wire as three requests
        // — not as three middleware retries each
        Exceptions::fake();
        Http::fake(['*/item/1*' => Http::response('', 500)]);

        // Act
        resolve(PoolsIdBatchesTestHost::class)->fetch([1]);

        // Assert
        expect(resolve(PooledTransport::class)->stats()->dispatched)->toBe(3);
        Http::assertSentCount(3);
    });
});

describe('pooled() pacing', function (): void {
    it('spaces a paced batch at the configured rate', function (): void {
        // Arrange
        $slept = [];
        Sleep::fake();
        Sleep::whenFakingSleep(function (CarbonInterval $duration) use (&$slept): void {
            $slept[] = (float) $duration->totalSeconds;
        });
        Http::fake(['*/item/*' => Http::response(['ok' => true])]);
        $host = new PoolsIdBatchesTestHost(rate: 20.0);

        // Act
        $host->fetch([1, 2, 3, 4]);

        // Assert
        // 20 req/s is one token every 0.05 s, and the first token is free — so four
        // ids wait three times, each step behind the last by that interval
        $waits = array_values(array_filter($slept, fn (float $seconds): bool => $seconds > 0.0));
        expect($waits)->toHaveCount(3)
            ->and($waits[0])->toEqualWithDelta(0.05, 0.01)
            ->and($waits[1] - $waits[0])->toEqualWithDelta(0.05, 0.01)
            ->and($waits[2] - $waits[1])->toEqualWithDelta(0.05, 0.01);
    });

    it('never sleeps for a batch whose service sets no rate', function (): void {
        // Arrange
        // null is TheTVDB's setting: nothing has measured its ceiling, so the window
        // width stays its only bound and an unmeasured throttle would only slow it
        Sleep::fake();
        Http::fake(['*/item/*' => Http::response(['ok' => true])]);
        $host = new PoolsIdBatchesTestHost(rate: null);

        // Act
        $host->fetch([1, 2, 3, 4]);

        // Assert
        Sleep::assertNeverSlept();
    });

    it('shortens the waits when the configured rate is raised', function (): void {
        // Arrange
        Http::fake(['*/item/*' => Http::response(['ok' => true])]);
        /** @var Closure(float): list<float> $run */
        $run = function (float $rate): array {
            // each rate is an independent run: the transport is a process-lifetime
            // singleton, so drop it rather than let one rate's pacing state carry
            // into the next
            app()->forgetInstance(PooledTransport::class);

            $slept = [];
            Sleep::fake();
            Sleep::whenFakingSleep(function (CarbonInterval $duration) use (&$slept): void {
                $slept[] = (float) $duration->totalSeconds;
            });

            (new PoolsIdBatchesTestHost(rate: $rate))->fetch([1, 2, 3]);

            return array_values(array_filter($slept, fn (float $seconds): bool => $seconds > 0.0));
        };

        // Act
        [$slow, $fast] = array_map($run, [10.0, 20.0]);

        // Assert
        // 10 req/s is a token every 0.1 s; doubling the rate to 20 req/s must halve
        // that first wait, not merely produce some wait
        expect($slow)->toHaveCount(2)
            ->and($fast)->toHaveCount(2)
            ->and($slow[0])->toEqualWithDelta(0.1, 0.01)
            ->and($fast[0])->toEqualWithDelta(0.05, 0.01)
            ->and($fast[0])->toBeLessThan($slow[0]);
    });

    it('halves the pacing rate after upstream push-back', function (): void {
        // Arrange
        // id 3 pushes back on its first two attempts and succeeds on the third, so
        // the run keeps dispatching past the 429s. Two re-queues, not one: a token
        // is spaced by the rate in force when its PREDECESSOR was taken, so a cut
        // only shows in the step after the dispatch that triggered it
        $slept = [];
        Sleep::fake();
        Sleep::whenFakingSleep(function (CarbonInterval $duration) use (&$slept): void {
            $slept[] = (float) $duration->totalSeconds;
        });
        $pushBacks = 0;
        Http::fake([
            '*/item/3*' => function () use (&$pushBacks) {
                if ($pushBacks >= 2) {
                    return Http::response(['id' => 3]);
                }

                $pushBacks++;

                return Http::response('', 429);
            },
            '*/item/*' => Http::response(['ok' => true]),
        ]);
        $host = new PoolsIdBatchesTestHost(rate: 20.0);

        // Act
        $host->fetch([1, 2, 3]);

        // Assert
        // three ids plus two re-queues is five dispatches, the first of them free.
        // The AIMD hold and climb are clock-driven, so pin only that the step after
        // the push-back grew — never an exact float
        $waits = array_values(array_filter($slept, fn (float $seconds): bool => $seconds > 0.0));
        expect($waits)->toHaveCount(4)
            ->and($waits[3] - $waits[2])->toBeGreaterThan(($waits[1] - $waits[0]) * 1.5);
    });
});
