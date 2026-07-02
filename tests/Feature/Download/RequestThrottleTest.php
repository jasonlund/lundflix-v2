<?php

declare(strict_types=1);

use App\Domains\Download\Exceptions\RateLimitExceeded;
use App\Domains\Download\Support\RequestThrottle;
use Carbon\CarbonInterval;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Sleep;

it('does not sleep on the first await of a fresh throttle', function (): void {
    // Arrange
    Cache::flush();
    Sleep::fake();
    $this->freezeTime();
    $throttle = new RequestThrottle;

    // Act
    $throttle->await();

    // Assert
    Sleep::assertNeverSlept();
});

it('backs off within the cap and delays the next request by the retry-after', function (): void {
    // Arrange
    Cache::flush();
    Sleep::fake();
    $this->freezeTime();
    $throttle = new RequestThrottle;
    $throttle->backoff(10);

    // Act
    $throttle->await();

    // Assert
    Sleep::assertSlept(fn (CarbonInterval $duration): bool => $duration->totalMilliseconds === 10000.0, times: 1);
});

it('falls back to a 60 second backoff that trips the wait cap', function (): void {
    // Arrange
    Cache::flush();
    Sleep::fake();
    $this->freezeTime();
    $throttle = new RequestThrottle;
    $throttle->backoff();

    // Act & Assert
    expect(fn () => $throttle->await())->toThrow(RateLimitExceeded::class);
});

it('trips the wait cap before sleeping when the backoff exceeds it', function (): void {
    // Arrange
    Cache::flush();
    Sleep::fake();
    $this->freezeTime();
    $throttle = new RequestThrottle;
    $throttle->backoff(45);

    // Act & Assert
    expect(fn () => $throttle->await())->toThrow(RateLimitExceeded::class);
    Sleep::assertNeverSlept();
});

it('spaces back-to-back awaits 6.5 seconds apart', function (): void {
    // Arrange
    Cache::flush();
    Sleep::fake();
    $this->freezeTime();
    $throttle = new RequestThrottle;

    // Act
    $throttle->await();
    $throttle->await();

    // Assert
    Sleep::assertSlept(fn (CarbonInterval $duration): bool => $duration->totalMilliseconds === 6500.0, times: 1);
});

it('does not wait when the reserved slot has already elapsed', function (): void {
    // Arrange
    Cache::flush();
    Sleep::fake();
    $this->freezeTime();
    $throttle = new RequestThrottle;
    $throttle->await();
    $this->travel(7)->seconds();

    // Act
    $throttle->await();

    // Assert
    Sleep::assertNeverSlept();
});
