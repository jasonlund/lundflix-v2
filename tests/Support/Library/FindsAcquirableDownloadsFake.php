<?php

declare(strict_types=1);

namespace Tests\Support\Library;

use App\Domains\Catalog\Data\UnitRef;
use App\Domains\Download\Contracts\FindsAcquirableDownloads;
use Override;

/**
 * A download mirror matching exactly the units it is constructed with, each pair
 * naming a unit and the `downloads.id` it resolves to — every other unit misses.
 * Units match by kind and id together, never by object identity.
 */
final readonly class FindsAcquirableDownloadsFake implements FindsAcquirableDownloads
{
    /** @var array<string, int> */
    private array $downloadIds;

    /**
     * @param  array{0: UnitRef, 1: int}  ...$matches
     */
    public function __construct(array ...$matches)
    {
        $this->downloadIds = collect($matches)
            ->mapWithKeys(fn (array $match): array => [$this->key($match[0]) => $match[1]])
            ->all();
    }

    #[Override]
    public function for(UnitRef $unit): ?int
    {
        return $this->downloadIds[$this->key($unit)] ?? null;
    }

    private function key(UnitRef $unit): string
    {
        return $unit->kind->value.':'.$unit->id;
    }
}
