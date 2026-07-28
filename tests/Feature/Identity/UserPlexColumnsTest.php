<?php

declare(strict_types=1);

use App\Domains\Identity\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('stores the numeric Plex account id in an integer column', function (): void {
    // Arrange
    // the migrated users table is what is under test, no state to set up

    // Act
    $type = Schema::getColumnType('users', '_plex_id');

    // Assert
    expect($type)->toBe('integer');
});

it('persists every _plex_* column on a user', function (): void {
    // Arrange
    $user = User::factory()->make([
        '_plex_id' => 12345678,
        '_plex_uuid' => 'a1b2c3d4e5f60718',
        '_plex_username' => 'plexowner',
        '_plex_thumb' => 'https://plex.tv/users/a1b2c3d4e5f60718/avatar?c=1750000000',
        '_plex_token' => 'sxWpYzQ1TkxAbCdEfGhI',
    ]);

    // Act
    $user->save();

    // Assert
    $this->assertDatabaseHas('users', [
        'id' => $user->id,
        '_plex_id' => 12345678,
        '_plex_uuid' => 'a1b2c3d4e5f60718',
        '_plex_username' => 'plexowner',
        '_plex_thumb' => 'https://plex.tv/users/a1b2c3d4e5f60718/avatar?c=1750000000',
    ]);
    expect(User::query()->findOrFail($user->id)->_plex_token)->toBe('sxWpYzQ1TkxAbCdEfGhI');
});

it('stores _plex_token encrypted at rest', function (): void {
    // Arrange
    $user = User::factory()->create(['_plex_token' => 'sxWpYzQ1TkxAbCdEfGhI']);

    // Act
    $stored = DB::table('users')->where('id', $user->id)->value('_plex_token');

    // Assert
    expect($stored)->not->toBe('sxWpYzQ1TkxAbCdEfGhI')
        ->and(User::query()->findOrFail($user->id)->_plex_token)->toBe('sxWpYzQ1TkxAbCdEfGhI');
});

it('omits _plex_token from a serialized user', function (): void {
    // Arrange
    $user = User::factory()->create([
        '_plex_username' => 'plexowner',
        '_plex_token' => 'sxWpYzQ1TkxAbCdEfGhI',
    ]);

    // Act
    $serialized = $user->toArray();

    // Assert
    expect($serialized)->not->toHaveKey('_plex_token')
        ->and($serialized)->toHaveKey('_plex_username');
});

it('rejects a second user with the same _plex_id', function (): void {
    // Arrange
    User::factory()->create(['_plex_id' => 12345678]);

    // Act & Assert
    expect(fn () => User::factory()->create(['_plex_id' => 12345678]))
        ->toThrow(QueryException::class);
});

it('creates a user with every _plex_* column null', function (): void {
    // Arrange
    // no state to set up: the null-column default is what is under test

    // Act
    $user = User::factory()->create();

    // Assert
    $this->assertDatabaseHas('users', [
        'id' => $user->id,
        '_plex_id' => null,
        '_plex_uuid' => null,
        '_plex_username' => null,
        '_plex_thumb' => null,
        '_plex_token' => null,
    ]);
});
