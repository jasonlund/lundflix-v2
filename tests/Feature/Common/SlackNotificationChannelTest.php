<?php

declare(strict_types=1);

use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Slack\SlackMessage;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Illuminate\Support\Str;

/**
 * Throwaway fixture notification exercising Laravel's `slack` channel — no
 * example notification ships in production.
 */
class SlackChannelTestNotification extends Notification
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

it('posts to the Slack chat.postMessage endpoint', function (): void {
    // Arrange
    Http::fake(['*slack.com/api/*' => Http::response(['ok' => true])]);
    config()->set('services.slack.notifications.bot_user_oauth_token', 'xoxb-test-token');
    config()->set('services.slack.notifications.channel', '#lundflix');

    // Act
    NotificationFacade::route('slack', config('services.slack.notifications.channel'))
        ->notify(new SlackChannelTestNotification);

    // Assert
    Http::assertSent(fn ($request): bool => Str::endsWith($request->url(), '/api/chat.postMessage'));
    Http::assertSentCount(1);
});

it('targets the configured channel', function (): void {
    // Arrange
    Http::fake(['*slack.com/api/*' => Http::response(['ok' => true])]);
    config()->set('services.slack.notifications.bot_user_oauth_token', 'xoxb-test-token');
    config()->set('services.slack.notifications.channel', '#lundflix');

    // Act
    NotificationFacade::route('slack', config('services.slack.notifications.channel'))
        ->notify(new SlackChannelTestNotification);

    // Assert
    Http::assertSent(fn ($request): bool => $request['channel'] === config('services.slack.notifications.channel'));
});

it('authenticates with the configured bot token', function (): void {
    // Arrange
    Http::fake(['*slack.com/api/*' => Http::response(['ok' => true])]);
    config()->set('services.slack.notifications.bot_user_oauth_token', 'xoxb-test-token');
    config()->set('services.slack.notifications.channel', '#lundflix');

    // Act
    NotificationFacade::route('slack', config('services.slack.notifications.channel'))
        ->notify(new SlackChannelTestNotification);

    // Assert
    Http::assertSent(fn ($request): bool => $request->hasHeader('Authorization', 'Bearer '.config('services.slack.notifications.bot_user_oauth_token')));
});
