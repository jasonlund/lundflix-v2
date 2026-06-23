<?php

use App\Domains\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

it('shares a null user for guests', function () {
    // Arrange — no authenticated user.

    // Act
    $response = $this->get('/');

    // Assert
    $response->assertInertia(fn (Assert $page) => $page
        ->where('auth.user', null)
    );
});

it('shares the authenticated user as a typed dto', function () {
    // Arrange
    $user = User::factory()->create();

    // Act
    $response = $this->actingAs($user)->get('/');

    // Assert
    $response->assertInertia(fn (Assert $page) => $page
        ->has('auth.user', fn (Assert $sharedUser) => $sharedUser
            ->where('id', $user->id)
            ->where('name', $user->name)
            ->where('email', $user->email)
        )
    );
});

it('omits sensitive fields from the shared user', function () {
    // Arrange
    $user = User::factory()->create();

    // Act
    $response = $this->actingAs($user)->get('/');

    // Assert
    $response->assertInertia(fn (Assert $page) => $page
        ->has('auth.user', fn (Assert $sharedUser) => $sharedUser
            ->missing('password')
            ->missing('remember_token')
            ->etc()
        )
    );
});
