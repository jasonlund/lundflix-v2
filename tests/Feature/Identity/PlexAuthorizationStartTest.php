<?php

declare(strict_types=1);

use App\Domains\Identity\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Inertia\Inertia;

/*
|--------------------------------------------------------------------------
| Start Plex authorization — FLIX-241 slice 4
|--------------------------------------------------------------------------
| POST /auth/plex mints a Plex linking PIN, stashes its id in the session, and
| hands the guest off to app.plex.tv with our callback as the forwardUrl. When
| Plex can't mint the PIN the guest bounces back to /login with the copy in
| errors.plex.
|
| Fixture (byte-exact real capture, tokens redacted):
|   tests/Fixtures/Common/plex/pin_create.json — fresh PIN (id 538114995,
|     code m6mijjn177ut0qaz02b9iedof)
|
| The empty 500 body and the code-less 2xx body are synthetic: a real capture
| can't express an upstream failure, nor a PIN Plex minted without a code.
*/

it('hands the guest to the Plex auth url carrying the PIN code and our callback', function (): void {
    // Arrange
    Http::fake([
        '*clients.plex.tv/api/v2/pins*' => Http::response(fixtureBytes('Common/plex/pin_create.json')),
    ]);

    // Act
    $response = $this->post(route('auth.plex.start'));

    // Assert
    $response->assertRedirectContains('https://app.plex.tv/auth#?');
    $response->assertRedirectContains('m6mijjn177ut0qaz02b9iedof');
    $response->assertRedirectContains(urlencode(route('auth.plex.callback')));
});

it('stores the minted PIN id in the session', function (): void {
    // Arrange
    Http::fake([
        '*clients.plex.tv/api/v2/pins*' => Http::response(fixtureBytes('Common/plex/pin_create.json')),
    ]);

    // Act
    $response = $this->post(route('auth.plex.start'));

    // Assert
    $response->assertSessionHas('plex_pin_id', 538114995);
});

it('bounces back to the login page with an error when Plex cannot mint a PIN', function (): void {
    // Arrange
    Http::fake([
        '*clients.plex.tv/api/v2/pins*' => Http::response('', 500),
    ]);

    // Act
    $response = $this->post(route('auth.plex.start'));

    // Assert
    $response->assertRedirect(route('login'));
    $response->assertSessionHasErrors([
        'plex' => 'We could not reach Plex. Please try again.',
    ]);
});

it('bounces back to the login page with an error when Plex mints a PIN without a code', function (): void {
    // Arrange
    Http::fake([
        '*clients.plex.tv/api/v2/pins*' => Http::response(['id' => 538114995]),
    ]);

    // Act
    $response = $this->post(route('auth.plex.start'));

    // Assert
    $response->assertRedirect(route('login'));
    $response->assertSessionHasErrors([
        'plex' => 'We could not reach Plex. Please try again.',
    ]);
});

it('gives an Inertia visit a location response the browser can follow', function (): void {
    // Arrange
    Http::fake([
        '*clients.plex.tv/api/v2/pins*' => Http::response(fixtureBytes('Common/plex/pin_create.json')),
    ]);

    // Act
    $response = $this->withHeaders([
        'X-Inertia' => 'true',
        'X-Inertia-Version' => Inertia::getVersion(),
    ])->post(route('auth.plex.start'));

    // Assert
    $response->assertStatus(409);
    $response->assertHeader('X-Inertia-Location');
    expect($response->headers->get('X-Inertia-Location'))
        ->toContain('https://app.plex.tv/auth#?')
        ->toContain('m6mijjn177ut0qaz02b9iedof');
});

it('throttles a guest who floods the Plex authorization start', function (): void {
    // Arrange
    // the limiter counts through the cache, so flush it to keep the count
    // deterministic no matter what a sibling test left behind
    Cache::flush();
    Http::fake([
        '*clients.plex.tv/api/v2/pins*' => Http::response(fixtureBytes('Common/plex/pin_create.json')),
    ]);
    foreach (range(1, 10) as $ignored) {
        $this->post(route('auth.plex.start'));
    }

    // Act
    $response = $this->post(route('auth.plex.start'));

    // Assert
    $response->assertStatus(429);
});

it('redirects authenticated users away from the Plex authorization start', function (): void {
    // Arrange
    $user = User::factory()->create();

    // Act
    $response = $this->actingAs($user)->post(route('auth.plex.start'));

    // Assert
    $response->assertRedirect(route('home'));
});
