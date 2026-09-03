<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Models\Concerns;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;

/**
 * A refused title is stored, never surfaced (ADR-0004), so what counts as
 * refusal is defined here once rather than restated at each ingest site.
 */
trait Refusable
{
    /**
     * The flags every title carries. Separate from refusalColumns() because an
     * overriding model has no parent:: for a trait method — it replaces it
     * outright — so the shared base has to be spreadable (see Movie).
     *
     * @var list<string>
     */
    protected const REFUSAL_COLUMNS = [
        '_imdb_isAdult',
        '_tmdb_adult',
        '_tmdb_softcore',
    ];

    /**
     * @return list<string>
     */
    protected static function refusalColumns(): array
    {
        return static::REFUSAL_COLUMNS;
    }

    public function isRefused(): bool
    {
        foreach (static::refusalColumns() as $column) {
            if ($this->{$column} === true) {
                return true;
            }
        }

        return false;
    }

    /**
     * Overrides Scout's always-true default (resolved with `insteadof` where the
     * two traits meet), so a refused title never reaches the index no matter
     * which write path saves it.
     */
    public function shouldBeSearchable(): bool
    {
        return ! $this->isRefused();
    }

    /**
     * A null flag is unknown, not refused, so it must survive the filter — which
     * a bare where($column, false) would drop.
     *
     * @param  Builder<static>  $query
     */
    #[Scope]
    protected function notRefused(Builder $query): void
    {
        foreach (static::refusalColumns() as $column) {
            $query->where(fn (Builder $query): Builder => $query
                ->whereNull($column)
                ->orWhere($column, false));
        }
    }
}
