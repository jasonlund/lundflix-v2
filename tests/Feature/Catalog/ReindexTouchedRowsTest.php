<?php

declare(strict_types=1);

use App\Domains\Catalog\Actions\ReindexTouchedRows;
use App\Domains\Catalog\Models\Movie;
use App\Domains\Catalog\Models\Show;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

/**
 * Stamp a row's `updated_at` without the model touching timestamps itself.
 */
$stampUpdatedAt = function (Movie|Show $row, CarbonImmutable $updatedAt): void {
    $row->newQuery()->whereKey($row->getKey())->update(['updated_at' => $updatedAt]);
};

/**
 * The heartbeat writer is asserted in its own slice; here it only has to exist.
 */
$write = function (string $line): void {};

describe('handle() watermark selection', function () use ($stampUpdatedAt, $write): void {
    it('passes every movie touched after the watermark to the engine and returns their count', function () use ($write): void {
        // Arrange
        $watermark = CarbonImmutable::now()->startOfSecond()->subHour();
        $touched = Movie::factory()->withTmdb()->count(3)->create();
        // Registered after the factory saves so their auto-syncs aren't captured.
        $capturedChunks = spyOnScoutEngine();

        // Act
        $count = new ReindexTouchedRows()->handle(Movie::class, $watermark, $write);

        // Assert
        expect(reindexedIds($capturedChunks()))->toEqualCanonicalizing($touched->modelKeys());
        expect($count)->toBe(3);
    });

    it('includes the row whose updated_at equals the watermark exactly', function () use ($stampUpdatedAt, $write): void {
        // Arrange
        $watermark = CarbonImmutable::now()->startOfSecond()->subHour();
        $onTheBoundary = Movie::factory()->withTmdb()->create();
        $justBefore = Movie::factory()->withTmdb()->create();
        $stampUpdatedAt($onTheBoundary, $watermark);
        $stampUpdatedAt($justBefore, $watermark->subSecond());
        $capturedChunks = spyOnScoutEngine();

        // Act
        $count = new ReindexTouchedRows()->handle(Movie::class, $watermark, $write);

        // Assert
        expect(reindexedIds($capturedChunks()))->toEqualCanonicalizing([$onTheBoundary->id]);
        expect($count)->toBe(1);
    });

    it('excludes rows untouched since the watermark', function () use ($stampUpdatedAt, $write): void {
        // Arrange
        $watermark = CarbonImmutable::now()->startOfSecond()->subHour();
        $touched = Movie::factory()->withTmdb()->create();
        $stale = Movie::factory()->withTmdb()->create();
        $stampUpdatedAt($stale, $watermark->subDay());
        $capturedChunks = spyOnScoutEngine();

        // Act
        $count = new ReindexTouchedRows()->handle(Movie::class, $watermark, $write);

        // Assert
        expect(reindexedIds($capturedChunks()))->toEqualCanonicalizing([$touched->id]);
        expect($count)->toBe(1);
    });

    it('reindexes a show class the same way', function () use ($stampUpdatedAt, $write): void {
        // Arrange
        $watermark = CarbonImmutable::now()->startOfSecond()->subHour();
        $touched = Show::factory()->withTvdb()->create();
        $stale = Show::factory()->withTvdb()->create();
        $stampUpdatedAt($stale, $watermark->subDay());
        $capturedChunks = spyOnScoutEngine();

        // Act
        $count = new ReindexTouchedRows()->handle(Show::class, $watermark, $write);

        // Assert
        expect(reindexedIds($capturedChunks()))->toEqualCanonicalizing([$touched->id]);
        expect($count)->toBe(1);
    });

    it('passes nothing to the engine and returns 0 when no rows are touched', function () use ($stampUpdatedAt, $write): void {
        // Arrange
        $watermark = CarbonImmutable::now()->startOfSecond()->subHour();
        $stale = Movie::factory()->withTmdb()->create();
        $stampUpdatedAt($stale, $watermark->subDay());
        $capturedChunks = spyOnScoutEngine();

        // Act
        $count = new ReindexTouchedRows()->handle(Movie::class, $watermark, $write);

        // Assert
        expect(reindexedIds($capturedChunks()))->toBe([]);
        expect($count)->toBe(0);
    });
});

describe('handle() chunked walk', function () use ($write): void {
    it('walks the touched rows in id-ordered chunks sized by the scout chunk config', function () use ($write): void {
        // Arrange
        config(['scout.chunk.searchable' => 2]);
        $watermark = CarbonImmutable::now()->startOfSecond()->subHour();
        $touched = Movie::factory()->withTmdb()->count(5)->create();
        $capturedChunks = spyOnScoutEngine();

        // Act
        new ReindexTouchedRows()->handle(Movie::class, $watermark, $write);

        // Assert
        $ascendingIds = $touched->modelKeys();
        sort($ascendingIds);
        expect($capturedChunks())->toHaveCount(3);
        expect($capturedChunks())->toBe(array_chunk($ascendingIds, 2));
    });
});

describe('handle() heartbeat output', function () use ($stampUpdatedAt): void {
    it('writes one heartbeat line per chunk carrying the cumulative row count', function (): void {
        // Arrange
        // `scout.queue` is pinned, not left to the environment: the heartbeat wording
        // is mode-dependent, and this is the byte-exact synchronous-mode shape.
        config(['scout.chunk.searchable' => 2, 'scout.queue' => false]);
        $watermark = CarbonImmutable::now()->startOfSecond()->subHour();
        Movie::factory()->withTmdb()->count(5)->create();
        $lines = [];
        $captureLine = function (string $line) use (&$lines): void {
            $lines[] = $line;
        };

        // Act
        new ReindexTouchedRows()->handle(Movie::class, $watermark, $captureLine);

        // Assert
        expect($lines)->toBe(['  [reindex 2]', '  [reindex 4]', '  [reindex 5]']);
    });

    it('marks the heartbeat queued when scout only queues the index writes', function (): void {
        // Arrange
        // With `scout.queue` on, `searchable()` dispatches a job instead of calling the
        // engine, so the count is rows QUEUED — the line has to say so or an operator
        // reads dispatch time as index time.
        config(['scout.chunk.searchable' => 2, 'scout.queue' => true]);
        Queue::fake();
        $watermark = CarbonImmutable::now()->startOfSecond()->subHour();
        Movie::factory()->withTmdb()->count(5)->create();
        $lines = [];
        $captureLine = function (string $line) use (&$lines): void {
            $lines[] = $line;
        };

        // Act
        new ReindexTouchedRows()->handle(Movie::class, $watermark, $captureLine);

        // Assert
        expect($lines)->toBe(['  [reindex 2 queued]', '  [reindex 4 queued]', '  [reindex 5 queued]']);
    });

    it('writes no heartbeat at all when no rows are touched', function () use ($stampUpdatedAt): void {
        // Arrange
        $watermark = CarbonImmutable::now()->startOfSecond()->subHour();
        $stale = Movie::factory()->withTmdb()->create();
        $stampUpdatedAt($stale, $watermark->subDay());
        $lines = [];
        $captureLine = function (string $line) use (&$lines): void {
            $lines[] = $line;
        };

        // Act
        new ReindexTouchedRows()->handle(Movie::class, $watermark, $captureLine);

        // Assert
        expect($lines)->toBe([]);
    });
});
