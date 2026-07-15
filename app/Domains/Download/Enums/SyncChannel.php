<?php

declare(strict_types=1);

namespace App\Domains\Download\Enums;

enum SyncChannel
{
    case Index;
    case Rss;
    case Detail;

    public function syncedAtColumn(): string
    {
        return match ($this) {
            self::Index => 'index_synced_at',
            self::Rss => 'rss_synced_at',
            self::Detail => 'detail_synced_at',
        };
    }
}
