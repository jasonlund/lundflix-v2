<?php

declare(strict_types=1);

use App\Domains\Notifications\Listeners\StoreSlackMessage;
use App\Domains\Notifications\Models\SlackMessage;
use App\Domains\PlexLibrary\Notifications\RecentlyAddedToPlex;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Http\Client\Response;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

/*
 * Notification::fake() short-circuits the send *before* NotificationSent fires, so
 * the listener under test can never be reached through it. The delivery cases below
 * therefore perform a real send over the sync queue against a faked Slack HTTP
 * response — the body is the shape chat.postMessage returns: ok, the resolved
 * channel id, the message ts, and the echoed message.
 *
 * The two rejection cases dispatch NotificationSent directly instead: an ok:false
 * body makes Laravel's own SlackChannel throw before it ever returns a response, and
 * a non-slack channel can't be produced by a Slack send at all, so a crafted event is
 * the only way to put the listener in front of those inputs.
 */

it('is registered as a listener for sent notifications', function (): void {
    // Arrange
    Event::fake();

    // Act
    // registration happens at boot; there is no action to perform

    // Assert
    Event::assertListening(NotificationSent::class, StoreSlackMessage::class);
});

it('logs a row for a confirmed Slack delivery', function (): void {
    // Arrange
    config()->set('services.slack.notifications.bot_user_oauth_token', 'xoxb-test-token');
    Http::fake(['*slack.com/api/*' => Http::response([
        'ok' => true,
        'channel' => 'C123',
        'ts' => '1234567890.123456',
        'message' => ['text' => 'Blade Runner 2049 (2017)'],
    ])]);

    // Act
    Notification::route('slack', '#lundflix')
        ->notify(new RecentlyAddedToPlex(['Blade Runner 2049 (2017)']));

    // Assert
    $logged = SlackMessage::query()->first();
    expect($logged)->not->toBeNull();
    expect($logged->channel)->toBe('C123');
    expect($logged->message_ts)->toBe('1234567890.123456');
    expect($logged->type)->toBe(RecentlyAddedToPlex::class);
    expect($logged->content)->toBe('Blade Runner 2049 (2017)');
    expect($logged->sent_at)->not->toBeNull();
});

it('logs nothing when Slack rejects the message', function (): void {
    // Arrange
    $rejected = new Response(new Psr7Response(200, [], (string) json_encode([
        'ok' => false,
        'error' => 'channel_not_found',
    ])));

    // Act
    event(new NotificationSent(
        new AnonymousNotifiable,
        new RecentlyAddedToPlex(['Blade Runner 2049 (2017)']),
        'slack',
        $rejected,
    ));

    // Assert
    expect(SlackMessage::query()->count())->toBe(0);
});

it('logs nothing for a delivery on a channel other than slack', function (): void {
    // Arrange
    $delivered = new Response(new Psr7Response(200, [], (string) json_encode([
        'ok' => true,
        'channel' => 'C123',
        'ts' => '1234567890.123456',
        'message' => ['text' => 'Blade Runner 2049 (2017)'],
    ])));

    // Act
    event(new NotificationSent(
        new AnonymousNotifiable,
        new RecentlyAddedToPlex(['Blade Runner 2049 (2017)']),
        'mail',
        $delivered,
    ));

    // Assert
    expect(SlackMessage::query()->count())->toBe(0);
});

it('updates the existing row when the same Slack message is delivered again', function (): void {
    // Arrange
    config()->set('services.slack.notifications.bot_user_oauth_token', 'xoxb-test-token');
    Http::fake(['*slack.com/api/*' => Http::sequence()
        ->push([
            'ok' => true,
            'channel' => 'C123',
            'ts' => '1234567890.123456',
            'message' => ['text' => 'Blade Runner 2049 (2017)'],
        ])
        ->push([
            'ok' => true,
            'channel' => 'C123',
            'ts' => '1234567890.123456',
            'message' => ['text' => 'Severance S02E04'],
        ])]);
    Notification::route('slack', '#lundflix')
        ->notify(new RecentlyAddedToPlex(['Blade Runner 2049 (2017)']));

    // Act
    Notification::route('slack', '#lundflix')
        ->notify(new RecentlyAddedToPlex(['Severance S02E04']));

    // Assert
    expect(SlackMessage::query()->count())->toBe(1);
    expect(SlackMessage::query()->sole()->content)->toBe('Severance S02E04');
});
