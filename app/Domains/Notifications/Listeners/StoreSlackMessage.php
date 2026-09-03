<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Listeners;

use App\Domains\Notifications\Models\SlackMessage;
use Illuminate\Http\Client\Response;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\Log;
use Throwable;

final readonly class StoreSlackMessage
{
    public function handle(NotificationSent $event): void
    {
        if ($event->channel !== 'slack') {
            return;
        }

        if (! $event->response instanceof Response) {
            return;
        }

        if ($event->response->json('ok') !== true) {
            return;
        }

        $channel = $event->response->json('channel');
        $messageTs = $event->response->json('ts');

        if (! is_string($channel) || ! is_string($messageTs)) {
            return;
        }

        try {
            SlackMessage::query()->updateOrCreate(
                [
                    'channel' => $channel,
                    'message_ts' => $messageTs,
                ],
                [
                    'type' => $event->notification::class,
                    'content' => $event->response->json('message.text', ''),
                    'sent_at' => now(),
                ],
            );
        } catch (Throwable $exception) {
            // The message is already delivered; failing to log it must never
            // surface as a delivery failure, so swallow and record instead.
            Log::error('Failed to store sent Slack message.', [
                'notification' => $event->notification::class,
                'channel' => $channel,
                'message_ts' => $messageTs,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
