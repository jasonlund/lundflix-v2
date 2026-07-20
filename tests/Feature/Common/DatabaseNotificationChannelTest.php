<?php

declare(strict_types=1);

use App\Domains\Identity\Models\User;
use Illuminate\Notifications\Notification;

use function Pest\Laravel\assertDatabaseHas;

/**
 * Throwaway fixture notification exercising Laravel's built-in `database`
 * channel — no example notification ships in production.
 */
class DatabaseChannelTestNotification extends Notification
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

it('persists a row morphed to the user with its data', function (): void {
    // Arrange
    $user = User::factory()->create();
    $notification = new DatabaseChannelTestNotification;

    // Act
    $user->notify($notification);

    // Assert
    assertDatabaseHas('notifications', [
        'notifiable_type' => $user->getMorphClass(),
        'notifiable_id' => $user->getKey(),
        'data' => json_encode(['message' => 'hello']),
    ]);
    expect($user->notifications)->toHaveCount(1);
});

it('unread by default', function (): void {
    // Arrange
    $user = User::factory()->create();
    $user->notify(new DatabaseChannelTestNotification);

    // Act
    $unread = $user->fresh()->unreadNotifications;

    // Assert
    expect($unread)->toHaveCount(1);
    expect($unread->first()->read_at)->toBeNull();
});
