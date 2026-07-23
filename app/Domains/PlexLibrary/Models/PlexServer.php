<?php

declare(strict_types=1);

namespace App\Domains\PlexLibrary\Models;

use App\Domains\PlexLibrary\Database\Factories\PlexServerFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PlexServer extends Model
{
    /** @use HasFactory<PlexServerFactory> */
    use HasFactory;

    /**
     * @return HasMany<PlexLibrary, $this>
     */
    public function libraries(): HasMany
    {
        return $this->hasMany(PlexLibrary::class);
    }

    protected static function newFactory(): Factory
    {
        return PlexServerFactory::new();
    }

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    protected function casts(): array
    {
        return [
            'synced_at' => 'immutable_datetime',
        ];
    }
}
