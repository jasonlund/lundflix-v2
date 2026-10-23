<?php

declare(strict_types=1);

namespace App\Domains\Library\Notifications;

use Illuminate\Notifications\Notification;

final class LikedUnitsArrived extends Notification
{
    /**
     * @param  list<string>  $lines
     */
    public function __construct(public array $lines) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array{lines: list<string>}
     */
    public function toArray(object $notifiable): array
    {
        return ['lines' => $this->lines];
    }
}
