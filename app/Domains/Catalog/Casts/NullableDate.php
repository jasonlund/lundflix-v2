<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Casts;

use Carbon\CarbonInterface;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Date;
use Throwable;

/**
 * Date-only cast that tolerates blank and sentinel raw values.
 *
 * Raw-source columns store dates exactly as the upstream API returned them, so
 * a column can hold `''` (unreleased) or `'0000-00-00'` (legacy) — values that
 * Carbon's plain `date` cast turns into garbage or throws on. This reads any
 * such value, and anything otherwise unparseable, back as `null`.
 *
 * @implements CastsAttributes<CarbonInterface, CarbonInterface|string|null>
 */
class NullableDate implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?CarbonInterface
    {
        if (in_array($value, [null, '', '0000-00-00'], true)) {
            return null;
        }

        try {
            return Date::parse($value)->startOfDay();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if (in_array($value, [null, '', '0000-00-00'], true)) {
            return null;
        }

        return Date::parse($value)->format('Y-m-d');
    }
}
