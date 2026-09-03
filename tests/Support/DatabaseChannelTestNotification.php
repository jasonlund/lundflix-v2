<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Notifications\Notification;

/**
 * Throwaway fixture notification exercising Laravel's built-in `database`
 * channel — no example notification ships in production.
 */
final class DatabaseChannelTestNotification extends Notification
{
    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array{message: string} */
    public function toDatabase(object $notifiable): array
    {
        return ['message' => 'hello'];
    }
}
