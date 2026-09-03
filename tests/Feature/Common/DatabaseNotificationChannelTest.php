<?php

declare(strict_types=1);

use App\Domains\Identity\Models\User;
use Tests\Support\DatabaseChannelTestNotification;

use function Pest\Laravel\assertDatabaseHas;

describe('database channel delivery', function (): void {
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

        // Act
        $user->notify(new DatabaseChannelTestNotification);

        // Assert
        $unread = $user->fresh()->unreadNotifications;

        expect($unread)->toHaveCount(1);
        expect($unread->first()->read_at)->toBeNull();
    });
});
