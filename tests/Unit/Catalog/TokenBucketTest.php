<?php

declare(strict_types=1);

use App\Domains\Catalog\Support\TokenBucket;

/*
|--------------------------------------------------------------------------
| TokenBucket is the pacer for the shared pooled transport: it computes the
| delay a caller owes before its next request and never sleeps, so the clock
| is injected and every test drives "now" by hand. Push-back from upstream
| halves the rate, and the rate then climbs back toward the configured target.
|--------------------------------------------------------------------------
*/

describe('waitFor() pacing', function (): void {
    it('grants the first token with no delay', function (): void {
        // Arrange
        $now = 0.0;
        $bucket = new TokenBucket(40.0, function () use (&$now): float {
            return $now;
        });

        // Act
        $actual = $bucket->waitFor();

        // Assert
        expect($actual)->toEqualWithDelta(0.0, 0.0001);
    });

    it('delays the next token by the token interval at the configured rate', function (): void {
        // Arrange
        $now = 0.0;
        $bucket = new TokenBucket(40.0, function () use (&$now): float {
            return $now;
        });
        $bucket->waitFor();

        // Act
        $actual = $bucket->waitFor();

        // Assert
        expect($actual)->toEqualWithDelta(0.025, 0.0001);
    });

    it('an unlimited bucket never delays', function (): void {
        // Arrange
        $now = 0.0;
        $bucket = new TokenBucket(null, function () use (&$now): float {
            return $now;
        });

        // Act
        $actual = [$bucket->waitFor(), $bucket->waitFor(), $bucket->waitFor()];

        // Assert
        expect($actual)->each->toEqualWithDelta(0.0, 0.0001);
    });
});

describe('penalize() back-off', function (): void {
    it('a 429 halves the rate', function (): void {
        // Arrange
        $now = 0.0;
        $bucket = new TokenBucket(40.0, function () use (&$now): float {
            return $now;
        });

        // Act
        $bucket->penalize();

        // Assert
        expect($bucket->rate())->toEqualWithDelta(20.0, 0.0001);
    });

    it('the halved rate holds for 30 s, then climbs 2 req/s per second', function (): void {
        // Arrange
        $now = 0.0;
        $bucket = new TokenBucket(40.0, function () use (&$now): float {
            return $now;
        });
        $bucket->penalize();

        // Act
        $rates = array_map(function (float $instant) use (&$now, $bucket): ?float {
            $now = $instant;

            return $bucket->rate();
        }, [29.0, 35.0]);

        // Assert
        expect($rates[0])->toEqualWithDelta(20.0, 0.0001);
        expect($rates[1])->toEqualWithDelta(30.0, 0.0001);
    });

    it('the climb never exceeds the configured target', function (): void {
        // Arrange
        $now = 0.0;
        $bucket = new TokenBucket(40.0, function () use (&$now): float {
            return $now;
        });

        // Act
        $bucket->penalize();

        // The ceiling only means something against a rate that was actually cut, so
        // sample inside the hold first: 60 s of raw climb would otherwise reach 80.
        // Assert
        $now = 25.0;
        expect($bucket->rate())->toEqualWithDelta(20.0, 0.0001);

        $now = 60.0;
        expect($bucket->rate())->toEqualWithDelta(40.0, 0.0001);
    });
});
