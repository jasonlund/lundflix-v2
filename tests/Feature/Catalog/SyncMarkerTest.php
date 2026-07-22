<?php

declare(strict_types=1);

use App\Domains\Catalog\Enums\SyncFeed;
use App\Domains\Catalog\Support\SyncMarker;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;

beforeEach(function (): void {
    Cache::flush();
});

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
    Cache::put(SyncFeed::TvdbShows->cacheKey(), now()->subHours(10)->toImmutable());

    // Act
    $window = resolve(SyncMarker::class)->window(SyncFeed::TvdbShows);

    // Assert
    expect($window->since->equalTo(now()->subHours(10)->subHours(6)))->toBeTrue();
});

it('floors the window at the 14-day cap for an old marker', function (): void {
    // Arrange
    Date::setTestNow('2026-07-16 12:00:00');
    Cache::put(SyncFeed::TvdbShows->cacheKey(), now()->subDays(30)->toImmutable());

    // Act
    $window = resolve(SyncMarker::class)->window(SyncFeed::TvdbShows);

    // Assert
    expect($window->since->equalTo(now()->subDays(14)))->toBeTrue();
});

it('isolates markers per feed by cache key', function (): void {
    // Arrange
    Date::setTestNow('2026-07-16 12:00:00');
    Cache::put(SyncFeed::TvdbShows->cacheKey(), now()->subDays(5)->toImmutable());

    // Act
    $window = resolve(SyncMarker::class)->window(SyncFeed::TmdbShows);

    // Assert
    expect($window->since->equalTo(now()->subHours(24)))->toBeTrue();
});

it('round-trips an advanced marker through the cache', function (): void {
    // Arrange
    Date::setTestNow('2026-07-16 12:00:00');
    $t = now()->toImmutable();

    // Act
    resolve(SyncMarker::class)->advance(SyncFeed::TvdbShows, $t);

    // Assert
    expect(resolve(SyncMarker::class)->window(SyncFeed::TvdbShows)->since->equalTo($t->subHours(6)))->toBeTrue();
});

it('leaves other feeds untouched when advancing one feed', function (): void {
    // Arrange
    Date::setTestNow('2026-07-16 12:00:00');

    // Act
    resolve(SyncMarker::class)->advance(SyncFeed::TvdbShows, now()->toImmutable());

    // Assert
    expect(resolve(SyncMarker::class)->window(SyncFeed::TmdbMovies)->since->equalTo(now()->subHours(24)))->toBeTrue();
});
