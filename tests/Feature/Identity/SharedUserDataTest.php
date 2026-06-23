<?php

declare(strict_types=1);

use App\Domains\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

it('shares a null user for guests', function (): void {
    // Arrange — no authenticated user.

    // Act
    $response = $this->get('/');

    // Assert
    $response->assertInertia(fn (Assert $page): AssertableInertia => $page
        ->where('auth.user', null)
    );
});

it('shares the authenticated user as a typed dto', function (): void {
    // Arrange
    $user = User::factory()->create();

    // Act
    $response = $this->actingAs($user)->get('/');

    // Assert
    $response->assertInertia(fn (Assert $page): AssertableInertia => $page
        ->has('auth.user', fn (Assert $sharedUser): AssertableInertia => $sharedUser
            ->where('id', $user->id)
            ->where('name', $user->name)
            ->where('email', $user->email)
        )
    );
});

it('omits sensitive fields from the shared user', function (): void {
    // Arrange
    $user = User::factory()->create();

    // Act
    $response = $this->actingAs($user)->get('/');

    // Assert
    $response->assertInertia(fn (Assert $page): AssertableInertia => $page
        ->has('auth.user', fn (Assert $sharedUser): AssertableInertia => $sharedUser
            ->missing('password')
            ->missing('remember_token')
            ->etc()
        )
    );
});
