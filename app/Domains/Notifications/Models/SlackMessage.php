<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Models;

use App\Domains\Notifications\Database\Factories\SlackMessageFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SlackMessage extends Model
{
    /** @use HasFactory<SlackMessageFactory> */
    use HasFactory;

    protected static function newFactory(): Factory
    {
        return SlackMessageFactory::new();
    }

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
        ];
    }
}
