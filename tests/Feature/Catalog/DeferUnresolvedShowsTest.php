<?php

declare(strict_types=1);

use App\Domains\Catalog\Actions\DeferUnresolvedShows;
use App\Domains\Catalog\Models\Show;
use Illuminate\Support\Facades\Date;

/*
|--------------------------------------------------------------------------
| DeferUnresolvedShows closes the hydrate leg's chunk: every candidate the
| chunk attempted and left without a tmdb_synced_at stamp has its attempt
| counted and its next try pushed out, so an unresolvable row stops being
| re-/find-ed at full rate on every run (FLIX-291).
|
| It reads the outcome off the rows themselves rather than being told it: the
| chunk's models were loaded BEFORE the reconcile and the hydrate wrote to
| them, so only the database knows which of them resolved.
|--------------------------------------------------------------------------
*/

describe('DeferUnresolvedShows chunk bookkeeping', function (): void {
    it('counts an attempt and pushes the retry out for a row the chunk left unsynced', function (): void {
        // Arrange
        Date::setTestNow('2026-07-16 12:00:00');
        $show = Show::factory()->withTvdb()->create(['_imdb_id' => 'tt0903747', '_tmdb_id' => null, 'tmdb_synced_at' => null]);

        // Act
        resolve(DeferUnresolvedShows::class)->handle(collect([$show]));

        // Assert
        $fresh = $show->fresh();
        expect($fresh->tmdb_unresolved_attempts)->toBe(1)
            ->and($fresh->tmdb_retry_after?->toDateTimeString())->toBe('2026-07-17 12:00:00');
    });

    it('leaves a row the chunk did hydrate alone', function (): void {
        // Arrange
        Date::setTestNow('2026-07-16 12:00:00');
        $show = Show::factory()->withTvdb()->create(['_tmdb_id' => 1399, 'tmdb_synced_at' => now()]);

        // Act
        resolve(DeferUnresolvedShows::class)->handle(collect([$show]));

        // Assert
        $fresh = $show->fresh();
        expect($fresh->tmdb_unresolved_attempts)->toBe(0)
            ->and($fresh->tmdb_retry_after)->toBeNull();
    });

    it('escalates the interval for a row already carrying attempts', function (): void {
        // A fourth failed attempt earns 2^3 = 8 days, so the residue thins out run
        // over run instead of holding flat at one fixed interval.
        // Arrange
        Date::setTestNow('2026-07-16 12:00:00');
        $show = Show::factory()->withTvdb()->create([
            '_imdb_id' => 'tt0903747',
            '_tmdb_id' => null,
            'tmdb_synced_at' => null,
            'tmdb_unresolved_attempts' => 3,
            'tmdb_retry_after' => now()->subDay(),
        ]);

        // Act
        resolve(DeferUnresolvedShows::class)->handle(collect([$show]));

        // Assert
        $fresh = $show->fresh();
        expect($fresh->tmdb_unresolved_attempts)->toBe(4)
            ->and($fresh->tmdb_retry_after?->toDateTimeString())->toBe('2026-07-24 12:00:00');
    });

    it('returns how many rows it deferred', function (): void {
        // Arrange
        Date::setTestNow('2026-07-16 12:00:00');
        $unresolved = Show::factory()->withTvdb()->count(2)->create(['tmdb_synced_at' => null]);
        $hydrated = Show::factory()->withTvdb()->create(['_tmdb_id' => 1399, 'tmdb_synced_at' => now()]);

        // Act
        $deferred = resolve(DeferUnresolvedShows::class)->handle($unresolved->push($hydrated));

        // Assert
        expect($deferred)->toBe(2);
    });

    it('touches no row outside the chunk', function (): void {
        // Arrange
        Date::setTestNow('2026-07-16 12:00:00');
        $inChunk = Show::factory()->withTvdb()->create(['tmdb_synced_at' => null]);
        $elsewhere = Show::factory()->withTvdb()->create(['tmdb_synced_at' => null]);

        // Act
        resolve(DeferUnresolvedShows::class)->handle(collect([$inChunk]));

        // Assert
        expect($elsewhere->fresh()->tmdb_unresolved_attempts)->toBe(0)
            ->and($elsewhere->fresh()->tmdb_retry_after)->toBeNull();
    });

    it('does not mark a deferred row as touched for the leg reindex', function (): void {
        // updated_at is the leg's reindex watermark. Deferring changes nothing a
        // search index holds, so stamping it would push the whole residue through
        // the engine every run — the cost this ticket exists to remove, moved.
        // Arrange
        Date::setTestNow('2026-07-16 12:00:00');
        $show = Show::factory()->withTvdb()->create(['tmdb_synced_at' => null]);
        Show::query()->whereKey($show->id)->toBase()->update(['updated_at' => '2026-01-01 00:00:00']);

        // Act
        resolve(DeferUnresolvedShows::class)->handle(collect([$show]));

        // Assert
        expect($show->fresh()->updated_at?->toDateTimeString())->toBe('2026-01-01 00:00:00');
    });
});
