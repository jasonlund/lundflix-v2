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
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/*
 * Notification::fake() short-circuits the send *before* NotificationSent fires, so
 * the listener under test can never be reached through it. The delivery cases below
 * therefore perform a real send over the sync queue against a faked Slack HTTP
 * response — the body is the shape chat.postMessage returns: ok, the resolved
 * channel id, the message ts, and the echoed message.
 *
 * The rejection cases dispatch NotificationSent directly instead: an ok:false body
 * makes Laravel's own SlackChannel throw before it ever returns a response, a
 * non-slack channel can't be produced by a Slack send at all, and a well-formed
 * chat.postMessage reply never carries a non-string channel/ts — so a crafted event
 * with a synthetic body is the only way to put the listener in front of those inputs.
 */

describe('StoreSlackMessage listener registration', function (): void {
    it('is registered as a listener for sent notifications', function (): void {
        // Arrange
        Event::fake();

        // Act
        // registration happens at boot; there is no action to perform

        // Assert
        Event::assertListening(NotificationSent::class, StoreSlackMessage::class);
    });
});

describe('StoreSlackMessage delivery logging', function (): void {
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
});

describe('StoreSlackMessage skipped deliveries', function (): void {
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

    it('logs nothing when the confirmed delivery carries a non-string channel or ts', function (array $body): void {
        // Arrange
        $delivered = new Response(new Psr7Response(200, [], (string) json_encode($body)));

        // Act
        event(new NotificationSent(
            new AnonymousNotifiable,
            new RecentlyAddedToPlex(['Blade Runner 2049 (2017)']),
            'slack',
            $delivered,
        ));

        // Assert
        expect(SlackMessage::query()->count())->toBe(0);
    })->with([
        'non-string channel' => [[
            'ok' => true,
            'channel' => 123,
            'ts' => '1234567890.123456',
            'message' => ['text' => 'Blade Runner 2049 (2017)'],
        ]],
        'non-string ts' => [[
            'ok' => true,
            'channel' => 'C123',
            'ts' => 1234567890,
            'message' => ['text' => 'Blade Runner 2049 (2017)'],
        ]],
    ]);
});

describe('StoreSlackMessage storage failures', function (): void {
    it('swallows and logs a storage failure rather than breaking the delivery', function (): void {
        // Arrange
        // The message is already delivered, so a write failure must not escape the
        // listener. Dropping `slack_messages` makes the real updateOrCreate raise a
        // genuine QueryException; SQLite's transactional DDL rolls the drop back with
        // RefreshDatabase. A throw here would surface as an errored test.
        $delivered = new Response(new Psr7Response(200, [], (string) json_encode([
            'ok' => true,
            'channel' => 'C123',
            'ts' => '1234567890.123456',
            'message' => ['text' => 'Blade Runner 2049 (2017)'],
        ])));
        $spy = Log::spy();
        Schema::drop('slack_messages');

        // Act
        event(new NotificationSent(
            new AnonymousNotifiable,
            new RecentlyAddedToPlex(['Blade Runner 2049 (2017)']),
            'slack',
            $delivered,
        ));

        // Assert
        $spy->shouldHaveReceived('error')->once()->withArgs(function (string $message, array $context): bool {
            $values = collect($context)->map(fn ($value): string => (string) $value);

            return $message !== ''
                && $context['notification'] === RecentlyAddedToPlex::class
                && $context['channel'] === 'C123'
                && $context['message_ts'] === '1234567890.123456'
                && $values->contains(fn (string $value): bool => Str::contains($value, 'slack_messages'));
        });
    });
});

describe('StoreSlackMessage repeat deliveries', function (): void {
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
});
