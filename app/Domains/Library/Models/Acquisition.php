<?php

declare(strict_types=1);

namespace App\Domains\Library\Models;

use App\Domains\Catalog\Data\UnitRef;
use App\Domains\Catalog\Enums\UnitKind;
use App\Domains\Library\Database\Factories\AcquisitionFactory;
use App\Domains\Library\Enums\AcquisitionStatus;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Override;

final class Acquisition extends Model
{
    /** @use HasFactory<AcquisitionFactory> */
    use HasFactory;

    public function unit(): UnitRef
    {
        return new UnitRef($this->unit_kind, $this->unit_id);
    }

    /**
     * @param  Builder<static>  $query
     */
    #[Scope]
    protected function forUnit(Builder $query, UnitRef $unit): void
    {
        $query->where('unit_kind', $unit->kind)->where('unit_id', $unit->id);
    }

    protected static function newFactory(): Factory
    {
        return AcquisitionFactory::new();
    }

    /**
     * @return array<string, mixed>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'unit_kind' => UnitKind::class,
            'unit_id' => 'integer',
            'status' => AcquisitionStatus::class,
        ];
    }
}
