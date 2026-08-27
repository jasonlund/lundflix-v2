<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Support;

use Generator;

final readonly class Batches
{
    /**
     * Cut a stream into fixed-size batches, tail included, pulling from the source
     * only as each batch fills — so an unbounded feed is never materialized and
     * only the batch in flight is held.
     *
     * Hand-rolled rather than `LazyCollection::chunk()`: callers hand in raw
     * generators, which `LazyCollection` rejects — it wants a generator *function*.
     *
     * @template TValue
     *
     * @param  iterable<mixed, TValue>  $items
     * @return Generator<int, list<TValue>>
     */
    public static function of(iterable $items, int $size): Generator
    {
        $batch = [];

        foreach ($items as $item) {
            $batch[] = $item;

            if (count($batch) >= $size) {
                yield $batch;
                $batch = [];
            }
        }

        if ($batch !== []) {
            yield $batch;
        }
    }
}
