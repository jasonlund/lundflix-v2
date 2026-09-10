<?php

declare(strict_types=1);

use App\Domains\Catalog\Models\Movie;
use App\Domains\Library\Actions\ToggleLikeBehavior;
use App\Domains\Library\Enums\Behavior;
use App\Domains\Library\Models\Like;
use App\Domains\Library\Models\LikeBehavior;
use Illuminate\Database\QueryException;

describe('ToggleLikeBehavior::handle() off and on', function (): void {
    it('keeps the row and clears its timestamp when a behavior is switched off', function (): void {
        // Arrange
        $like = Like::factory()->for(Movie::factory(), 'likeable')->create();
        $enabled = LikeBehavior::factory()->for($like)->create(['behavior' => Behavior::Acquire]);

        // Act
        $toggled = resolve(ToggleLikeBehavior::class)->handle($like, Behavior::Acquire);

        // Assert
        expect($toggled->getKey())->toBe($enabled->getKey())
            ->and($toggled->enabled_at)->toBeNull();
        $this->assertDatabaseCount('like_behaviors', 1);
        $this->assertDatabaseHas('like_behaviors', [
            'id' => $enabled->id,
            'like_id' => $like->id,
            'behavior' => 'acquire',
            'enabled_at' => null,
        ]);
    });

    // The instant itself is the claim here, so the clock is frozen rather than
    // asserted as merely set.
    it('stamps the row enabled again when the behavior is switched back on', function (): void {
        // Arrange
        $like = Like::factory()->for(Movie::factory(), 'likeable')->create();
        $disabled = LikeBehavior::factory()->for($like)->disabled()->create(['behavior' => Behavior::Acquire]);
        $this->travelTo('2026-09-09 12:00:00');

        // Act
        $toggled = resolve(ToggleLikeBehavior::class)->handle($like, Behavior::Acquire);

        // Assert
        expect($toggled->getKey())->toBe($disabled->getKey())
            ->and($toggled->enabled_at?->toDateTimeString())->toBe('2026-09-09 12:00:00');
        $this->assertDatabaseCount('like_behaviors', 1);
        $this->assertDatabaseHas('like_behaviors', [
            'id' => $disabled->id,
            'behavior' => 'acquire',
            'enabled_at' => '2026-09-09 12:00:00',
        ]);
    });
});

describe('ToggleLikeBehavior::handle() independence', function (): void {
    it('leaves the other behavior of the same like untouched', function (): void {
        // Arrange
        $like = Like::factory()->for(Movie::factory(), 'likeable')->create();
        LikeBehavior::factory()->for($like)->create(['behavior' => Behavior::Acquire]);
        $notify = LikeBehavior::factory()->for($like)->create([
            'behavior' => Behavior::Notify,
            'enabled_at' => '2026-01-01 00:00:00',
        ]);

        // Act
        resolve(ToggleLikeBehavior::class)->handle($like, Behavior::Acquire);

        // Assert
        expect($notify->fresh()?->enabled_at?->toDateTimeString())->toBe('2026-01-01 00:00:00');
        $this->assertDatabaseCount('like_behaviors', 2);
        $this->assertDatabaseHas('like_behaviors', [
            'id' => $notify->id,
            'behavior' => 'notify',
            'enabled_at' => '2026-01-01 00:00:00',
        ]);
    });
});

describe('like_behaviors uniqueness', function (): void {
    it('rejects a second row for a behavior the like already carries', function (): void {
        // Arrange
        $like = Like::factory()->for(Movie::factory(), 'likeable')->create();
        LikeBehavior::factory()->for($like)->create(['behavior' => Behavior::Acquire]);

        // Act & Assert
        expect(fn (): LikeBehavior => LikeBehavior::factory()->for($like)->create(['behavior' => Behavior::Acquire]))
            ->toThrow(QueryException::class);
        $this->assertDatabaseCount('like_behaviors', 1);
    });
});
