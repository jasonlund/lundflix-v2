<?php

declare(strict_types=1);

use App\Domains\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

it('redirects guests from the home page to the login page', function (): void {
    // Arrange
    // no authenticated user

    // Act
    $response = $this->get('/');

    // Assert
    $response->assertRedirect(route('login'));
});

it('renders the welcome page for authenticated users', function (): void {
    // Arrange
    $user = User::factory()->create();

    // Act
    $response = $this->actingAs($user)->get('/');

    // Assert
    $response->assertInertia(fn (Assert $page): Assert => $page
        ->component('Welcome')
    );
});

it('renders the login page for guests', function (): void {
    // Arrange
    // no authenticated user

    // Act
    $response = $this->get('/login');

    // Assert
    $response->assertInertia(fn (Assert $page): Assert => $page
        ->component('auth/Login')
    );
});

it('redirects authenticated users from the login page to home', function (): void {
    // Arrange
    $user = User::factory()->create();

    // Act
    $response = $this->actingAs($user)->get('/login');

    // Assert
    $response->assertRedirect(route('home'));
});
