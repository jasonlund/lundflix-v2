<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Support;

use App\Domains\Catalog\Services\PooledTransport;
use GuzzleHttp\Promise\EachPromise;
use Illuminate\Http\Client\Promises\LazyPromise;
use Illuminate\Support\Sleep;
use Iterator;
use Override;

/**
 * The live work queue {@see PooledTransport} rolls its window over.
 *
 * A hand-written Iterator, because the two obvious shorthands both drop a
 * re-queue:
 *
 * - a **Generator** returns the moment its queue runs dry, and a finished
 *   generator can never yield again — so a retry pushed after the last in-flight
 *   slot settled would be dropped on the floor;
 * - an **ArrayIterator** would have its revived cursor skipped, because
 *   {@see EachPromise::step()} calls `next()` on the iterator *after* running
 *   the settlement callback: an appended element lands behind a cursor already
 *   past the end, and that `next()` steps past it.
 *
 * This iterator instead consumes the queue as it walks it, so the very `next()`
 * that follows a settlement pulls whatever that settlement pushed.
 *
 * Not `readonly` — the queue and the cursor over it are the whole point.
 */
final class PooledWindow implements Iterator
{
    /**
     * Work waiting for a window slot, oldest first.
     *
     * @var list<array{0: int, 1: mixed}>
     */
    private array $queue = [];

    /**
     * The slot the window is currently looking at, or null when the queue was
     * dry the last time the cursor moved.
     *
     * @var array{0: int, 1: mixed}|null
     */
    private ?array $cursor = null;

    /**
     * @param  ?TokenBucket  $bucket  the batch's pacer, or null to leave it unpaced
     */
    public function __construct(private readonly ?TokenBucket $bucket = null) {}

    /**
     * Append a slot to the back of the queue. Safe to call while the window is
     * draining — that is what makes a re-queue land in the same window rather
     * than in a second pass.
     */
    public function push(int $slot, mixed $promise): void
    {
        $this->queue[] = [$slot, $promise];
    }

    /**
     * A Laravel async request is a {@see LazyPromise}, so nothing leaves the
     * process until it is built — which happens here, as the window pulls the
     * slot, rather than when the slot was queued. That is what keeps the window
     * rolling instead of handing the whole batch to curl at once.
     */
    #[Override]
    public function current(): mixed
    {
        $promise = $this->cursor[1] ?? null;

        if ($promise instanceof LazyPromise && $promise->promiseNeedsBuilt()) {
            $this->pace();

            return $promise->buildPromise();
        }

        return $promise;
    }

    #[Override]
    public function key(): mixed
    {
        return $this->cursor[0] ?? null;
    }

    /**
     * Shifts off the live queue rather than advancing a position into a fixed
     * array, which is what lets the slot a settlement callback just pushed be
     * the one this pull lands on — {@see EachPromise::step()} runs that callback
     * and then calls this.
     */
    #[Override]
    public function next(): void
    {
        $this->cursor = array_shift($this->queue);
    }

    /**
     * The queue is consumed as it is walked, so there is no start to return to:
     * the single rewind {@see EachPromise} performs before its first pull is
     * just that first pull.
     */
    #[Override]
    public function rewind(): void
    {
        $this->cursor = array_shift($this->queue);
    }

    /**
     * Re-reads the queue when the cursor is dry, so a slot pushed after the
     * cursor ran off the end still counts as work remaining — the window is
     * finished only when a settlement leaves nothing behind it.
     */
    #[Override]
    public function valid(): bool
    {
        if ($this->cursor === null) {
            $this->cursor = array_shift($this->queue);
        }

        return $this->cursor !== null;
    }

    /**
     * Hold for the pacer's next token, immediately before the promise above is
     * built.
     *
     * This is the only place the wait can go. The transport queues every id of a
     * batch up front, in one unbroken build pass, so a wait placed on that path
     * would elapse before a single request had left the process — spacing the
     * queueing and pacing nothing real. A slot leaves the process when its
     * {@see LazyPromise} is built, which is here, so a token taken here maps one
     * to one onto a request on the wire.
     *
     * Waited through {@see Sleep} rather than a bare `usleep`, so the pacing is
     * observable to (and fakeable by) the suite instead of really costing wall
     * clock in every test that pools.
     */
    private function pace(): void
    {
        $seconds = $this->bucket?->waitFor() ?? 0.0;

        if ($seconds <= 0.0) {
            return;
        }

        Sleep::for($seconds)->seconds();
    }
}
