<?php

declare(strict_types=1);

use App\Domains\Catalog\Enums\SyncFeed;
use App\Domains\Catalog\Support\SyncMarker;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/**
 * Seeds one feed's marker row directly, bypassing `advance()` on purpose: these
 * tests prove `window()` reads a stored row, and that `advance()` updates an
 * existing row rather than acting as its own fixture.
 */
function storeMarkerRow(SyncFeed $feed, string $markedAt): void
{
    DB::table('catalog_sync_markers')->insert([
        'feed' => $feed->key(),
        'marked_at' => $markedAt,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

describe('window() sync windows', function (): void {
    it('falls back to a 24h window when the feed has no marker', function (): void {
        // Arrange
        Date::setTestNow('2026-07-16 12:00:00');

        // Act
        $window = resolve(SyncMarker::class)->window(SyncFeed::TvdbShows);

        // Assert
        expect($window->since->equalTo(now()->subHours(24)))->toBeTrue();
        expect($window->until->equalTo(now()))->toBeTrue();
    });

    it('applies a 6h overlap behind a stored marker', function (): void {
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

    it('leaves the window uncapped when the feed has no marker', function (): void {
        // Arrange
        Date::setTestNow('2026-07-16 12:00:00');

        // Act
        $window = resolve(SyncMarker::class)->window(SyncFeed::TvdbShows);

        // Assert
        expect($window->isCapped())->toBeFalse();
        expect($window->uncoveredStartDate())->toBeNull();
    });

    it('derives the window start from the feed row minus the 6h overlap', function (): void {
        // Arrange
        Date::setTestNow('2026-07-16 12:00:00');
        storeMarkerRow(SyncFeed::TvdbShows, '2026-07-16 02:00:00');

        // Act
        $window = resolve(SyncMarker::class)->window(SyncFeed::TvdbShows);

        // Assert
        expect($window->since->toIso8601String())->toBe('2026-07-15T20:00:00+00:00');
    });

    it('isolates markers per feed', function (): void {
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
    it('round-trips an advanced marker through the table', function (): void {
        // Arrange
        Date::setTestNow('2026-07-16 12:00:00');
        $t = now()->toImmutable();

        // Act
        resolve(SyncMarker::class)->advance(SyncFeed::TvdbShows, $t);

        // Assert
        expect(resolve(SyncMarker::class)->window(SyncFeed::TvdbShows)->since->equalTo($t->subHours(6)))->toBeTrue();
    });

    it('inserts a row carrying the run-start instant for a feed with no marker', function (): void {
        // Arrange
        Date::setTestNow('2026-07-16 12:00:00');

        // Act
        resolve(SyncMarker::class)->advance(SyncFeed::TvdbShows, CarbonImmutable::parse('2026-07-16 11:30:00'));

        // Assert
        expect(DB::table('catalog_sync_markers')->pluck('feed')->all())->toBe(['tvdb_shows']);
        expect(syncMarker(SyncFeed::TvdbShows))->toBe('2026-07-16T11:30:00+00:00');
    });

    it('updates the feed row in place rather than adding a second', function (): void {
        // Arrange
        Date::setTestNow('2026-07-16 12:00:00');
        storeMarkerRow(SyncFeed::TvdbShows, '2026-07-15 09:00:00');

        // Act
        resolve(SyncMarker::class)->advance(SyncFeed::TvdbShows, CarbonImmutable::parse('2026-07-16 11:30:00'));

        // Assert
        expect(DB::table('catalog_sync_markers')->where('feed', 'tvdb_shows')->count())->toBe(1);
        expect(syncMarker(SyncFeed::TvdbShows))->toBe('2026-07-16T11:30:00+00:00');
    });

    it('advances only the named feed, leaving the other three rows untouched', function (): void {
        // Arrange
        Date::setTestNow('2026-07-16 12:00:00');
        foreach (SyncFeed::cases() as $feed) {
            storeMarkerRow($feed, '2026-07-15 09:00:00');
        }

        // Act
        resolve(SyncMarker::class)->advance(SyncFeed::TmdbMovies, CarbonImmutable::parse('2026-07-16 11:30:00'));

        // Assert
        // The advanced feed is asserted alongside the three untouched ones: without it
        // a marker store that wrote nothing at all would satisfy "untouched" vacuously.
        expect(syncMarker(SyncFeed::TmdbMovies))->toBe('2026-07-16T11:30:00+00:00');
        expect(syncMarker(SyncFeed::TvdbShows))->toBe('2026-07-15T09:00:00+00:00');
        expect(syncMarker(SyncFeed::TvdbEpisodes))->toBe('2026-07-15T09:00:00+00:00');
        expect(syncMarker(SyncFeed::TmdbShows))->toBe('2026-07-15T09:00:00+00:00');
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

describe('importFromCache() cache backfill', function (): void {
    it('carries a cached marker over as the feed row instant', function (): void {
        // Arrange
        Date::setTestNow('2026-07-16 12:00:00');
        Cache::forever('catalog:sync:marker:tvdb_shows', '2026-07-14T08:30:00+00:00');

        // Act
        resolve(SyncMarker::class)->importFromCache();

        // Assert
        expect(syncMarker(SyncFeed::TvdbShows))->toBe('2026-07-14T08:30:00+00:00');
    });

    it('writes no row for a feed with nothing cached', function (): void {
        // Arrange
        Date::setTestNow('2026-07-16 12:00:00');
        Cache::forever('catalog:sync:marker:tvdb_shows', '2026-07-14T08:30:00+00:00');

        // Act
        resolve(SyncMarker::class)->importFromCache();

        // Assert
        expect(syncMarker(SyncFeed::TmdbMovies))->toBeNull();
    });

    // FLIX-287: `cache.serializable_classes` is false, so an object written by an
    // older build reads back as `__PHP_Incomplete_Class`, not as a date. Such a feed
    // must be skipped — never written, never thrown on.
    it('skips a feed whose cached value is not a string', function (): void {
        // Arrange
        Date::setTestNow('2026-07-16 12:00:00');
        Cache::forever('catalog:sync:marker:tvdb_shows', CarbonImmutable::parse('2026-07-14 08:30:00'));

        // Act
        resolve(SyncMarker::class)->importFromCache();

        // Assert
        expect(syncMarker(SyncFeed::TvdbShows))->toBeNull();
    });
});
