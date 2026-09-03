<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Slack\SlackMessage;

/**
 * Throwaway fixture notification exercising Laravel's `slack` channel — no
 * example notification ships in production.
 */
final class SlackChannelTestNotification extends Notification
{
    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['slack'];
    }

    public function toSlack(object $notifiable): SlackMessage
    {
        return (new SlackMessage)->text('hello');
    }
}
