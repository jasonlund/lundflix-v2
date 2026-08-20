<?php

declare(strict_types=1);

use App\Domains\Catalog\Actions\ReindexTouchedRows;
use App\Domains\Catalog\Models\Movie;
use App\Domains\Catalog\Models\Show;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Scout\EngineManager;
use Laravel\Scout\Engines\Engine;

uses(RefreshDatabase::class);

/**
 * Point Scout at a spy engine and hand back a getter for the model keys the
 * engine has been handed, each `update()` call kept in its own group.
 *
 * The suite runs `SCOUT_DRIVER=collection`, whose engine writes nothing a DB
 * assertion can see — the ids the engine receives are the only observable
 * evidence of what was reindexed. The groups are kept unflattened because the
 * call boundaries ARE the chunking under test: a flat list of ids can't tell
 * one 5-row call from three smaller ones. Tests that only care about *which*
 * rows were reindexed flatten with `$reindexedIds()`.
 *
 * Call this AFTER the rows are arranged: the `Searchable` trait syncs on every
 * factory save, so a spy registered earlier also captures the create-time syncs
 * and every row looks reindexed.
 *
 * @return Closure(): list<list<int|string>>
 */
$spyOnScoutEngine = function (): Closure {
    $captured = [];

    $spy = Mockery::spy(Engine::class);

    $spy->shouldReceive('update')->andReturnUsing(
        function (EloquentCollection $models) use (&$captured): void {
            $captured[] = $models->modelKeys();
        },
    );

    resolve(EngineManager::class)->extend('spy', fn (): Engine => $spy);
    config(['scout.driver' => 'spy']);

    return function () use (&$captured): array {
        return $captured;
    };
};

/**
 * Every key the engine was handed, in call order, with the chunk grouping dropped.
 *
 * @param  list<list<int|string>>  $chunks
 * @return list<int|string>
 */
$reindexedIds = fn (array $chunks): array => collect($chunks)->flatten()->all();

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

it('passes every movie touched after the watermark to the engine and returns their count', function () use ($spyOnScoutEngine, $reindexedIds, $write): void {
    // Arrange
    $watermark = CarbonImmutable::now()->startOfSecond()->subHour();
    $touched = Movie::factory()->withTmdb()->count(3)->create();
    // Registered after the factory saves so their auto-syncs aren't captured.
    $capturedChunks = $spyOnScoutEngine();

    // Act
    $count = new ReindexTouchedRows()->handle(Movie::class, $watermark, $write);

    // Assert
    expect($reindexedIds($capturedChunks()))->toEqualCanonicalizing($touched->modelKeys());
    expect($count)->toBe(3);
});

it('includes the row whose updated_at equals the watermark exactly', function () use ($spyOnScoutEngine, $reindexedIds, $stampUpdatedAt, $write): void {
    // Arrange
    $watermark = CarbonImmutable::now()->startOfSecond()->subHour();
    $onTheBoundary = Movie::factory()->withTmdb()->create();
    $justBefore = Movie::factory()->withTmdb()->create();
    $stampUpdatedAt($onTheBoundary, $watermark);
    $stampUpdatedAt($justBefore, $watermark->subSecond());
    $capturedChunks = $spyOnScoutEngine();

    // Act
    $count = new ReindexTouchedRows()->handle(Movie::class, $watermark, $write);

    // Assert
    expect($reindexedIds($capturedChunks()))->toEqualCanonicalizing([$onTheBoundary->id]);
    expect($count)->toBe(1);
});

it('excludes rows untouched since the watermark', function () use ($spyOnScoutEngine, $reindexedIds, $stampUpdatedAt, $write): void {
    // Arrange
    $watermark = CarbonImmutable::now()->startOfSecond()->subHour();
    $touched = Movie::factory()->withTmdb()->create();
    $stale = Movie::factory()->withTmdb()->create();
    $stampUpdatedAt($stale, $watermark->subDay());
    $capturedChunks = $spyOnScoutEngine();

    // Act
    $count = new ReindexTouchedRows()->handle(Movie::class, $watermark, $write);

    // Assert
    expect($reindexedIds($capturedChunks()))->toEqualCanonicalizing([$touched->id]);
    expect($count)->toBe(1);
});

it('reindexes a show class the same way', function () use ($spyOnScoutEngine, $reindexedIds, $stampUpdatedAt, $write): void {
    // Arrange
    $watermark = CarbonImmutable::now()->startOfSecond()->subHour();
    $touched = Show::factory()->withTvdb()->create();
    $stale = Show::factory()->withTvdb()->create();
    $stampUpdatedAt($stale, $watermark->subDay());
    $capturedChunks = $spyOnScoutEngine();

    // Act
    $count = new ReindexTouchedRows()->handle(Show::class, $watermark, $write);

    // Assert
    expect($reindexedIds($capturedChunks()))->toEqualCanonicalizing([$touched->id]);
    expect($count)->toBe(1);
});

it('passes nothing to the engine and returns 0 when no rows are touched', function () use ($spyOnScoutEngine, $reindexedIds, $stampUpdatedAt, $write): void {
    // Arrange
    $watermark = CarbonImmutable::now()->startOfSecond()->subHour();
    $stale = Movie::factory()->withTmdb()->create();
    $stampUpdatedAt($stale, $watermark->subDay());
    $capturedChunks = $spyOnScoutEngine();

    // Act
    $count = new ReindexTouchedRows()->handle(Movie::class, $watermark, $write);

    // Assert
    expect($reindexedIds($capturedChunks()))->toBe([]);
    expect($count)->toBe(0);
});

it('walks the touched rows in id-ordered chunks sized by the scout chunk config', function () use ($spyOnScoutEngine, $write): void {
    // Arrange
    config(['scout.chunk.searchable' => 2]);
    $watermark = CarbonImmutable::now()->startOfSecond()->subHour();
    $touched = Movie::factory()->withTmdb()->count(5)->create();
    $capturedChunks = $spyOnScoutEngine();

    // Act
    new ReindexTouchedRows()->handle(Movie::class, $watermark, $write);

    // Assert
    $ascendingIds = $touched->modelKeys();
    sort($ascendingIds);
    expect($capturedChunks())->toHaveCount(3);
    expect($capturedChunks())->toBe(array_chunk($ascendingIds, 2));
});

it('writes one heartbeat line per chunk carrying the cumulative row count', function (): void {
    // Arrange
    config(['scout.chunk.searchable' => 2]);
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
