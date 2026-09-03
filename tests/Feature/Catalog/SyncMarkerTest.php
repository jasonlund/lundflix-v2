<?php

declare(strict_types=1);

use App\Domains\Catalog\Enums\SyncFeed;
use App\Domains\Catalog\Support\SyncMarker;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;

beforeEach(function (): void {
    Cache::flush();
});

describe('window() sync windows', function (): void {
    it('falls back to a 24h window when no marker is cached', function (): void {
        // Arrange
        Date::setTestNow('2026-07-16 12:00:00');

        // Act
        $window = resolve(SyncMarker::class)->window(SyncFeed::TvdbShows);

        // Assert
        expect($window->since->equalTo(now()->subHours(24)))->toBeTrue();
        expect($window->until->equalTo(now()))->toBeTrue();
    });

    it('applies a 6h overlap behind a cached marker', function (): void {
        // Arrange
        Date::setTestNow('2026-07-16 12:00:00');
        resolve(SyncMarker::class)->advance(SyncFeed::TvdbShows, now()->subHours(10)->toImmutable());

        // Act
        $window = resolve(SyncMarker::class)->window(SyncFeed::TvdbShows);

        // Assert
        expect($window->since->equalTo(now()->subHours(10)->subHours(6)))->toBeTrue();
    });

    it('floors the window at the 14-day cap for an old marker', function (): void {
        // Arrange
        Date::setTestNow('2026-07-16 12:00:00');
        resolve(SyncMarker::class)->advance(SyncFeed::TvdbShows, now()->subDays(30)->toImmutable());

        // Act
        $window = resolve(SyncMarker::class)->window(SyncFeed::TvdbShows);

        // Assert
        expect($window->since->equalTo(now()->subDays(14)))->toBeTrue();
    });

    it('flags the window capped when the marker predates the 14-day floor', function (): void {
        // Arrange
        Date::setTestNow('2026-07-16 12:00:00');
        resolve(SyncMarker::class)->advance(SyncFeed::TvdbShows, now()->subDays(30)->toImmutable());

        // Act
        $window = resolve(SyncMarker::class)->window(SyncFeed::TvdbShows);

        // Assert
        expect($window->isCapped())->toBeTrue();
    });

    it('names the marker-derived start the 14-day floor discarded', function (): void {
        // Arrange
        Date::setTestNow('2026-07-16 12:00:00');
        resolve(SyncMarker::class)->advance(SyncFeed::TvdbShows, CarbonImmutable::parse('2026-06-16 03:00:00'));

        // Act
        $window = resolve(SyncMarker::class)->window(SyncFeed::TvdbShows);

        // Assert
        // The discarded start is the marker minus the 6h overlap, not the bare marker:
        // this marker sits at 03:00, so the overlap carries it back across midnight and
        // a bare-marker answer would read 2026-06-16.
        expect($window->uncoveredStartDate())->toBe('2026-06-15');
    });

    it('leaves the window uncapped for a marker inside the cap', function (): void {
        // Arrange
        Date::setTestNow('2026-07-16 12:00:00');
        resolve(SyncMarker::class)->advance(SyncFeed::TvdbShows, now()->subDays(5)->toImmutable());

        // Act
        $window = resolve(SyncMarker::class)->window(SyncFeed::TvdbShows);

        // Assert
        expect($window->isCapped())->toBeFalse();
        expect($window->uncoveredStartDate())->toBeNull();
    });

    it('leaves the window uncapped when no marker is cached', function (): void {
        // Arrange
        Date::setTestNow('2026-07-16 12:00:00');

        // Act
        $window = resolve(SyncMarker::class)->window(SyncFeed::TvdbShows);

        // Assert
        expect($window->isCapped())->toBeFalse();
        expect($window->uncoveredStartDate())->toBeNull();
    });

    it('isolates markers per feed by cache key', function (): void {
        // Arrange
        Date::setTestNow('2026-07-16 12:00:00');
        resolve(SyncMarker::class)->advance(SyncFeed::TvdbShows, now()->subDays(5)->toImmutable());

        // Act
        $window = resolve(SyncMarker::class)->window(SyncFeed::TmdbShows);

        // Assert
        expect($window->since->equalTo(now()->subHours(24)))->toBeTrue();
    });
});

describe('advance() marker persistence', function (): void {
    it('round-trips an advanced marker through the cache', function (): void {
        // Arrange
        Date::setTestNow('2026-07-16 12:00:00');
        $t = now()->toImmutable();

        // Act
        resolve(SyncMarker::class)->advance(SyncFeed::TvdbShows, $t);

        // Assert
        expect(resolve(SyncMarker::class)->window(SyncFeed::TvdbShows)->since->equalTo($t->subHours(6)))->toBeTrue();
    });

    it('persists the marker as a string, never as an object', function (): void {
        // Arrange
        Date::setTestNow('2026-07-16 12:00:00');

        // Act
        resolve(SyncMarker::class)->advance(SyncFeed::TvdbShows, now()->toImmutable());

        // Assert
        // The regression this pins: `cache.serializable_classes` is false, so a cached
        // object comes back as __PHP_Incomplete_Class and the marker is write-only.
        expect(Cache::get(SyncFeed::TvdbShows->cacheKey()))->toBe('2026-07-16T12:00:00+00:00');
    });

    it('falls back to the 24h window for a marker an older build left as an object', function (): void {
        // Arrange
        Date::setTestNow('2026-07-16 12:00:00');
        Cache::forever(SyncFeed::TvdbShows->cacheKey(), now()->subHours(10)->toImmutable());

        // Act
        $window = resolve(SyncMarker::class)->window(SyncFeed::TvdbShows);

        // Assert
        // The unreadable value degrades rather than throwing, so the four markers
        // already poisoned on production need no manual forget.
        expect($window->since->equalTo(now()->subHours(24)))->toBeTrue();
    });

    it('leaves other feeds untouched when advancing one feed', function (): void {
        // Arrange
        Date::setTestNow('2026-07-16 12:00:00');

        // Act
        resolve(SyncMarker::class)->advance(SyncFeed::TvdbShows, now()->toImmutable());

        // Assert
        expect(resolve(SyncMarker::class)->window(SyncFeed::TmdbMovies)->since->equalTo(now()->subHours(24)))->toBeTrue();
    });
});
