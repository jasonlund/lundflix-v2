<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Actions;

use Closure;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

final readonly class ReindexTouchedRows
{
    /**
     * @param  class-string<Model>  $model
     * @param  Closure(string): void  $write
     * @return int total rows passed to searchable()
     */
    public function handle(string $model, DateTimeInterface $watermark, Closure $write): int
    {
        $reindexed = 0;
        $mode = $this->queuesIndexing() ? ' queued' : '';

        $model::query()
            ->where('updated_at', '>=', $watermark)
            ->chunkById((int) config('scout.chunk.searchable'), function (Collection $rows) use (&$reindexed, $write, $mode): void {
                $rows->searchable();

                $reindexed += $rows->count();

                $write("  [reindex {$reindexed}{$mode}]");
            });

        return $reindexed;
    }

    /**
     * Whether `searchable()` merely QUEUES the index writes instead of performing them.
     *
     * `Searchable::queueMakeSearchable()` indexes inline only while `scout.queue` is
     * falsy; otherwise it dispatches a job and returns immediately. Production runs
     * `SCOUT_QUEUE=true`, so a caller timing this phase is timing the dispatch — the
     * output has to say "queued", not "indexed", or the elapsed seconds read as the
     * indexing budget. Public because the callers word their own phase lines.
     */
    public function queuesIndexing(): bool
    {
        return (bool) config('scout.queue');
    }
}
