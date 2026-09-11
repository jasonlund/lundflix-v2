<?php

declare(strict_types=1);

namespace App\Domains\Library\Models;

use App\Domains\Catalog\Enums\UnitKind;
use App\Domains\Identity\Models\User;
use App\Domains\Library\Database\Factories\LikeNotificationFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Override;

final class LikeNotification extends Model
{
    /** @use HasFactory<LikeNotificationFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected static function newFactory(): Factory
    {
        return LikeNotificationFactory::new();
    }

    /**
     * @return array<string, mixed>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'unit_kind' => UnitKind::class,
            'notified_at' => 'datetime',
        ];
    }
}
