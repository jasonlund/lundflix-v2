<?php

declare(strict_types=1);

namespace Tests\Support\Library;

use App\Domains\Catalog\Data\UnitRef;
use App\Domains\PlexLibrary\Contracts\ReportsPresence;
use Illuminate\Support\Collection;
use Override;

/**
 * A media server holding exactly the units it is constructed with. Units match
 * by kind and id together, never by object identity, since a test builds a fresh
 * UnitRef for the same unit wherever it needs one.
 */
final readonly class ReportsPresenceFake implements ReportsPresence
{
    /** @var list<string> */
    private array $presentKeys;

    public function __construct(UnitRef ...$present)
    {
        $this->presentKeys = collect($present)
            ->map(fn (UnitRef $unit): string => $this->key($unit))
            ->values()
            ->all();
    }

    #[Override]
    public function has(UnitRef $unit): bool
    {
        return in_array($this->key($unit), $this->presentKeys, true);
    }

    /**
     * @param  iterable<UnitRef>  $units
     * @return Collection<int, UnitRef>
     */
    #[Override]
    public function present(iterable $units): Collection
    {
        return collect($units)
            ->filter(fn (UnitRef $unit): bool => $this->has($unit))
            ->values();
    }

    private function key(UnitRef $unit): string
    {
        return $unit->kind->value.':'.$unit->id;
    }
}
