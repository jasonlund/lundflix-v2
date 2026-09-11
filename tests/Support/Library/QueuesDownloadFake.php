<?php

declare(strict_types=1);

namespace Tests\Support\Library;

use App\Domains\Download\Contracts\QueuesDownload;
use ArrayObject;
use Closure;
use Override;
use Throwable;

/**
 * A fetch path that requests nothing and only remembers what it was handed, so a
 * test can read back which download ids were queued and how many times. The log
 * is an ArrayObject held in a readonly property: the class must stay readonly, and
 * mutating the object it points at never reassigns the property.
 *
 * A download id mapped to a Throwable fails with it instead. The id is logged
 * before the throw, so the log reads back every attempt, failed ones included.
 *
 * A download id mapped to a side effect runs it while the fetch is in flight,
 * standing in for what the world does meanwhile — another run recording the
 * same unit, say.
 */
final readonly class QueuesDownloadFake implements QueuesDownload
{
    /** @var ArrayObject<int, int> */
    private ArrayObject $queuedIds;

    /**
     * @param  array<int, Throwable>  $failures
     * @param  array<int, Closure(): void>  $sideEffects
     */
    public function __construct(private array $failures = [], private array $sideEffects = [])
    {
        $this->queuedIds = new ArrayObject;
    }

    #[Override]
    public function queue(int $downloadId): void
    {
        $this->queuedIds->append($downloadId);

        if (array_key_exists($downloadId, $this->sideEffects)) {
            ($this->sideEffects[$downloadId])();
        }

        if (array_key_exists($downloadId, $this->failures)) {
            throw $this->failures[$downloadId];
        }
    }

    /**
     * @return list<int>
     */
    public function queued(): array
    {
        return array_values($this->queuedIds->getArrayCopy());
    }
}
