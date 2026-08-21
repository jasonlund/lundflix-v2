<?php

declare(strict_types=1);

use App\Domains\Download\Exceptions\RateLimitExceeded;
use App\Domains\Download\Support\RequestThrottle;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Sleep;

describe('RequestThrottle await()', function (): void {
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

    it('spaces a back-to-back await by a random 100-250ms', function (): void {
        // Arrange
        Cache::flush();
        Sleep::fake();
        $this->freezeTime();
        $throttle = new RequestThrottle;
        $now = now()->getTimestampMs();

        // Act
        $throttle->await();

        // Assert
        $gap = (int) Cache::get('download:request-throttle:next-slot') - $now;
        expect($gap)->toBeGreaterThanOrEqual(100)->toBeLessThanOrEqual(250);
    });

    it('accumulates independent random gaps across two successive awaits', function (): void {
        // Arrange
        Cache::flush();
        Sleep::fake();
        $this->freezeTime();
        $throttle = new RequestThrottle;
        $now = now()->getTimestampMs();

        // Act
        $throttle->await();
        $throttle->await();

        // Assert
        $advance = (int) Cache::get('download:request-throttle:next-slot') - $now;
        expect($advance)->toBeGreaterThanOrEqual(200)->toBeLessThanOrEqual(500);
    });

    it('surfaces a lock timeout as a domain rate limit failure', function (): void {
        // Arrange
        $lock = Mockery::mock(Lock::class);
        $lock->shouldReceive('block')->andThrow(new LockTimeoutException);
        Cache::shouldReceive('lock')->andReturn($lock);
        $throttle = new RequestThrottle;

        // Act & Assert
        expect(fn () => $throttle->await())->toThrow(RateLimitExceeded::class);
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
});
