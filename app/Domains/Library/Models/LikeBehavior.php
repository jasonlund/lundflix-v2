<?php

declare(strict_types=1);

namespace App\Domains\Library\Models;

use App\Domains\Library\Database\Factories\LikeBehaviorFactory;
use App\Domains\Library\Enums\Behavior;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Override;

final class LikeBehavior extends Model
{
    /** @use HasFactory<LikeBehaviorFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<Like, $this>
     */
    public function like(): BelongsTo
    {
        return $this->belongsTo(Like::class);
    }

    protected static function newFactory(): Factory
    {
        return LikeBehaviorFactory::new();
    }

    /**
     * @return array<string, mixed>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'behavior' => Behavior::class,
            'enabled_at' => 'datetime',
        ];
    }
}
