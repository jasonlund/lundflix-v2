<?php

declare(strict_types=1);

use App\Domains\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

describe('home route access', function (): void {
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
});

describe('login route access', function (): void {
    it('renders the login page for guests', function (): void {
        // Arrange
        // no authenticated user

        // Act
        $response = $this->get('/login');

        // Assert
        $response->assertInertia(fn (Assert $page): Assert => $page
            ->component('identity/Login')
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

    // A guest who navigates straight to /login has no intended URL saved, so the
    // login response falls through to config('fortify.home') — that fallback has to
    // be the home route this app actually serves.
    it('redirects guests to home after a successful login', function (): void {
        // Arrange
        $user = User::factory()->create();

        // Act
        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        // Assert
        $response->assertRedirect(route('home'));
        $this->assertAuthenticatedAs($user);
    });
});
