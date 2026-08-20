<?php

declare(strict_types=1);

use App\Domains\PlexLibrary\Notifications\RecentlyAddedToPlex;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| No Queue::fake() / Notification::fake() in this file
|--------------------------------------------------------------------------
|
| phpunit.xml sets QUEUE_CONNECTION=sync, so the ShouldQueue notification is
| delivered inline and Http::fake() observes the real Slack request. Faking the
| queue or the notifier would swallow the very request these tests assert on.
| NotifyRecentlyAddedTest already owns the "it is queued" assertion.
|
*/

describe('RecentlyAddedToPlex Slack delivery', function (): void {
    it('posts the digest lines as newline-joined Slack text', function (): void {
        // Arrange
        Http::fake(['*slack.com/api/*' => Http::response(['ok' => true])]);
        config()->set('services.slack.notifications.bot_user_oauth_token', 'xoxb-test-token');
        config()->set('services.slack.notifications.channel', '#lundflix');
        $lines = ['Blade Runner 2049 (2017)', 'Severance S02E04'];

        // Act
        NotificationFacade::route('slack', config('services.slack.notifications.channel'))
            ->notify(new RecentlyAddedToPlex($lines));

        // Assert
        Http::assertSent(fn ($request): bool => Str::endsWith($request->url(), '/api/chat.postMessage')
            && $request['text'] === "Blade Runner 2049 (2017)\nSeverance S02E04");
        Http::assertSentCount(1);
    });

    it('targets the configured channel with the configured bot token', function (): void {
        // Arrange
        Http::fake(['*slack.com/api/*' => Http::response(['ok' => true])]);
        config()->set('services.slack.notifications.bot_user_oauth_token', 'xoxb-test-token');
        config()->set('services.slack.notifications.channel', '#lundflix');
        $lines = ['Blade Runner 2049 (2017)', 'Severance S02E04'];

        // Act
        NotificationFacade::route('slack', config('services.slack.notifications.channel'))
            ->notify(new RecentlyAddedToPlex($lines));

        // Assert
        Http::assertSent(fn ($request): bool => $request['channel'] === config('services.slack.notifications.channel')
            && $request->hasHeader('Authorization', 'Bearer '.config('services.slack.notifications.bot_user_oauth_token')));
    });

    // Slack is the only delivery channel: in-app delivery is deferred, so a stored
    // notifications row would mean 'database' had crept into via().
    it('stores no in-app notification row', function (): void {
        // Arrange
        Http::fake(['*slack.com/api/*' => Http::response(['ok' => true])]);
        config()->set('services.slack.notifications.bot_user_oauth_token', 'xoxb-test-token');
        config()->set('services.slack.notifications.channel', '#lundflix');
        $lines = ['Blade Runner 2049 (2017)', 'Severance S02E04'];

        // Act
        NotificationFacade::route('slack', config('services.slack.notifications.channel'))
            ->notify(new RecentlyAddedToPlex($lines));

        // Assert
        expect(DB::table('notifications')->count())->toBe(0);
    });
});
