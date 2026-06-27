<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Casts;

use App\Domains\Catalog\Enums\Genre;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Read-time mapper for the raw IMDb genres column.
 *
 * The column stores genres exactly as IMDb returned them (a JSON array of raw
 * strings, including any IMDb doesn't agree on). This maps those raw strings to
 * known {@see Genre} cases at read time — dropping unrecognized ones — so the
 * normalization lives in the read path, never at ingest.
 *
 * @implements CastsAttributes<Collection<int, Genre>, iterable<int, Genre|string>>
 */
class ImdbGenres implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     * @return Collection<int, Genre>
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): Collection
    {
        if ($value === null) {
            return Collection::make();
        }

        $decoded = json_decode((string) $value, true) ?? [];

        return Collection::make(Genre::fromRawValues($decoded));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        $mapped = Collection::make($value)
            ->map(fn (Genre|string $genre): string => $genre instanceof Genre ? $genre->value : $genre)
            ->values()
            ->all();

        return json_encode($mapped);
    }
}
