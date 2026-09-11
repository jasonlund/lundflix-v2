<?php

declare(strict_types=1);

namespace App\Domains\Library\Database\Factories;

use App\Domains\Catalog\Data\UnitRef;
use App\Domains\Catalog\Enums\UnitKind;
use App\Domains\Library\Enums\AcquisitionStatus;
use App\Domains\Library\Models\Acquisition;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Acquisition>
 */
final class AcquisitionFactory extends Factory
{
    protected $model = Acquisition::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'unit_kind' => UnitKind::Movie,
            'unit_id' => fake()->numberBetween(1, 1_000_000),
            'download_id' => fake()->numberBetween(1, 1_000_000),
            'status' => AcquisitionStatus::Queued,
        ];
    }

    public function forUnit(UnitRef $unit): static
    {
        return $this->state(fn (): array => [
            'unit_kind' => $unit->kind,
            'unit_id' => $unit->id,
        ]);
    }
}
