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

        $model::query()
            ->where('updated_at', '>=', $watermark)
            ->chunkById((int) config('scout.chunk.searchable'), function (Collection $rows) use (&$reindexed, $write): void {
                $rows->searchable();

                $reindexed += $rows->count();

                $write("  [reindex {$reindexed}]");
            });

        return $reindexed;
    }
}
