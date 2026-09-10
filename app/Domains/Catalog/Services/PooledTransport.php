<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Services;

use App\Domains\Catalog\Data\PooledAttempt;
use App\Domains\Catalog\Data\TransportStats;
use App\Domains\Catalog\Support\PooledWindow;
use App\Domains\Catalog\Support\TokenBucket;
use App\Providers\HttpClientServiceProvider;
use GuzzleHttp\Handler\CurlMultiHandler;
use GuzzleHttp\Promise\EachPromise;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * The one HTTP transport every Catalog pooled fan-out dispatches through, so a
 * whole sync process shares a single connection rather than opening fresh ones
 * per batch.
 *
 * Not `readonly` — the transport carries the live connection and the running
 * dispatch tally behind {@see stats()}, which a readonly class cannot hold.
 */
final class PooledTransport
{
    /**
     * Seconds allowed for the TCP+TLS handshake alone. Short on purpose: a peer
     * that will not connect promptly is worth abandoning to the retry seam
     * rather than holding a window slot open behind it.
     */
    private const int CONNECT_TIMEOUT_SECONDS = 5;

    /**
     * Seconds allowed for a whole request. Long enough for a slow upstream
     * response, short enough that one stalled stream cannot pin a slot for the
     * rest of the run.
     */
    private const int REQUEST_TIMEOUT_SECONDS = 20;

    /**
     * Attempts a single request gets before its outcome is handed to the caller
     * as-is: the first dispatch plus two re-queues, matching the global retry
     * seam's cap for the single-request path.
     */
    private const int MAX_ATTEMPTS = 3;

    /**
     * The single curl multi handle every request in the process rides. Sharing
     * it is the whole point: connections (and their negotiated HTTP/2 sessions)
     * survive between batches instead of being torn down and re-handshaked.
     */
    private readonly CurlMultiHandler $handler;

    /**
     * Requests actually handed to the transport, accumulated across every
     * {@see fetch()} call so {@see stats()} describes the process, not a batch.
     * A re-queued attempt is a request of its own and counts again.
     */
    private int $dispatched = 0;

    /**
     * One pacer per configured rate, held for the life of the transport.
     *
     * Per rate rather than one for the transport, because TMDB (paced) and TVDB
     * (unpaced) share this singleton and neither may inherit the other's spacing
     * or its push-back penalty. Held here rather than rebuilt per {@see fetch()},
     * because the cursor and the AIMD state are exactly what has to survive the
     * thousands of batches a reseed makes — a fresh bucket per call would forget
     * every 429 the moment the batch that earned it ended.
     *
     * @var array<string, TokenBucket>
     */
    private array $buckets = [];

    public function __construct()
    {
        $this->handler = new CurlMultiHandler;
    }

    /**
     * Dispatch every request through one rolling window at most $concurrency
     * wide, handing each final outcome to $onResult exactly once under the key
     * it was submitted with. A request that fails in flight yields its Throwable
     * rather than throwing, so one bad id cannot sink the rest of the batch —
     * whereas a builder that throws while assembling its request propagates and
     * ends the whole call.
     *
     * A retryable outcome — a rejection, or a 429/5xx — is re-queued into the
     * same window (up to {@see MAX_ATTEMPTS} attempts) instead of waited out:
     * a blocking backoff here would stall every other stream multiplexed on the
     * shared connection, which is the cost this transport exists to remove.
     *
     * Upstream push-back (a 429) is fed to the batch's pacer as well as retried,
     * so the whole process slows down rather than only the id that was refused.
     *
     * @param  iterable<int|string, callable(PendingRequest): (Response|PromiseInterface)>  $requests
     * @param  callable(int|string, Response|Throwable): void  $onResult
     * @param  ?float  $rate  requests per second to pace at, or null to leave unpaced
     */
    public function fetch(iterable $requests, callable $onResult, int $concurrency, ?float $rate = null): void
    {
        $bucket = $this->bucket($rate);

        $window = new PooledWindow($bucket);

        $nextSlot = 0;

        // Held for the life of the batch, not consumed on dispatch: a settled
        // slot has to name its key, and a retried one has to rebuild its request.
        /** @var array<int, PooledAttempt> $slots */
        $slots = [];

        $dispatch = function (PooledAttempt $attempt) use ($window, &$nextSlot, &$slots): void {
            $slot = $nextSlot++;

            $slots[$slot] = $attempt;
            $this->dispatched++;

            $window->push($slot, ($attempt->build)($this->pendingRequest()));
        };

        $settle = function (int $slot, Response|Throwable $result) use ($onResult, $dispatch, $bucket, &$slots): void {
            $attempt = $slots[$slot];

            if ($bucket instanceof TokenBucket && $result instanceof Response && $result->status() === 429) {
                $bucket->penalize();
            }

            if ($attempt->number < self::MAX_ATTEMPTS && $this->isRetryable($result)) {
                $dispatch(new PooledAttempt($attempt->key, $attempt->build, $attempt->number + 1));

                return;
            }

            // Reported once per key, on its last attempt — the caller sees one
            // outcome per id however many times it was re-queued.
            $onResult($attempt->key, $result);
        };

        // The build pass is separate from the wait below, and unguarded, and must
        // stay that way: a builder throws only while assembling the request — a
        // service's shared auth failing, e.g. the synchronous /login inside TVDB's
        // configure() — and no id in the batch can survive that, so it has to reach
        // the caller. Only a settled rejection is a per-id failure, which is what
        // the `rejected` handler below absorbs. One try/catch spanning both passes
        // would downgrade that fatal auth failure into a silent per-id miss for
        // every id in the batch.
        foreach ($requests as $key => $build) {
            $dispatch(new PooledAttempt($key, $build(...), 1));
        }

        (new EachPromise($window, [
            'concurrency' => max(1, $concurrency),
            'fulfilled' => static function (mixed $result, int $slot) use ($settle): void {
                $settle($slot, $result);
            },
            'rejected' => static function (Throwable $reason, int $slot) use ($settle): void {
                $settle($slot, $reason);
            },
        ]))->promise()->wait();
    }

    /**
     * What this transport has dispatched so far this process.
     */
    public function stats(): TransportStats
    {
        return new TransportStats($this->dispatched);
    }

    /**
     * The pacer for a configured rate, created once and reused thereafter.
     *
     * A null rate is unpaced, and gets no bucket at all rather than a bucket
     * whose every wait is zero — an unpaced batch then never reaches the sleep
     * seam, where even a zero wait leaves a record ({@see PooledWindow::pace()}).
     * Zero and negative fall through the same guard because neither is a rate to
     * begin with: the token interval is 1/rate, so a bucket built on one would
     * divide by zero the first time it was asked how long to wait.
     */
    private function bucket(?float $rate): ?TokenBucket
    {
        if ($rate === null || $rate <= 0.0) {
            return null;
        }

        return $this->buckets[(string) $rate] ??= new TokenBucket($rate);
    }

    /**
     * Whether an outcome is worth another attempt. A rejection never reached a
     * status at all, so it always is; a settled response is judged by the one
     * status policy the app already applies to its single-request path, which
     * leaves a 404 alone — that is a miss decoding to null, and retrying every
     * deleted upstream title would multiply a sync's traffic.
     */
    private function isRetryable(Response|Throwable $result): bool
    {
        return $result instanceof Throwable
            || HttpClientServiceProvider::isRetryableStatus($result->status());
    }

    /**
     * A pooled request: bound to the shared handler, negotiating HTTP/2 so many
     * ids multiplex over one connection, and carrying no `Connection: close`
     * (which tore the socket down after every response — the exact cost this
     * transport exists to stop paying).
     *
     * Built without the app's global HTTP configuration, because that is where
     * the global retry middleware lives and it backs off with a blocking sleep
     * inside the handler — on this path that would freeze every other stream on
     * the shared connection, and stack its attempts on top of the window's.
     *
     * The wrapper has to enclose the `createPendingRequest()` call rather than
     * the send: the factory hands `globalMiddleware` to the PendingRequest
     * constructor (`Factory::newPendingRequest()`), so a request built outside
     * the wrapper carries the middleware however it is dispatched later. Same
     * factory throughout, so Http::fake() still intercepts.
     */
    private function pendingRequest(): PendingRequest
    {
        return Http::withoutGlobalConfiguration(fn (): PendingRequest => Http::createPendingRequest()
            ->setHandler($this->handler)
            ->async()
            ->withOptions(['version' => 2.0])
            ->connectTimeout(self::CONNECT_TIMEOUT_SECONDS)
            ->timeout(self::REQUEST_TIMEOUT_SECONDS));
    }
}
