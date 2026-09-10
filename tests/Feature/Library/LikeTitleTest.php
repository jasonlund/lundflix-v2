<?php

declare(strict_types=1);

use App\Domains\Catalog\Models\Movie;
use App\Domains\Catalog\Models\Show;
use App\Domains\Identity\Models\User;
use App\Domains\Library\Actions\LikeTitle;
use App\Domains\Library\Enums\Behavior;
use App\Domains\Library\Models\LikeBehavior;

describe('LikeTitle::handle() relationship creation', function (): void {
    it('creates the relationship when a user likes a movie', function (): void {
        // Arrange
        $user = User::factory()->create();
        $movie = Movie::factory()->create();

        // Act
        $like = resolve(LikeTitle::class)->handle($user, $movie);

        // Assert
        $this->assertDatabaseCount('likes', 1);
        $this->assertDatabaseHas('likes', [
            'user_id' => $user->id,
            'likeable_type' => 'movie',
            'likeable_id' => $movie->id,
        ]);
        expect($like->exists)->toBeTrue()
            ->and($like->user_id)->toBe($user->id)
            ->and($like->likeable_id)->toBe($movie->id);
    });

    it('creates the relationship when a user likes a show', function (): void {
        // Arrange
        $user = User::factory()->create();
        $show = Show::factory()->create();

        // Act
        $like = resolve(LikeTitle::class)->handle($user, $show);

        // Assert
        $this->assertDatabaseCount('likes', 1);
        $this->assertDatabaseHas('likes', [
            'user_id' => $user->id,
            'likeable_type' => 'show',
            'likeable_id' => $show->id,
        ]);
        expect($like->exists)->toBeTrue()
            ->and($like->user_id)->toBe($user->id)
            ->and($like->likeable_id)->toBe($show->id);
    });

    it('creates no second relationship when the same title is liked twice', function (): void {
        // Arrange
        $user = User::factory()->create();
        $movie = Movie::factory()->create();
        $first = resolve(LikeTitle::class)->handle($user, $movie);

        // Act
        $second = resolve(LikeTitle::class)->handle($user, $movie);

        // Assert
        $this->assertDatabaseCount('likes', 1);
        expect($second->exists)->toBeTrue()
            ->and($second->getKey())->toBe($first->getKey());
    });

    it('names the liked title by morph alias rather than class name', function (): void {
        // Arrange
        $user = User::factory()->create();
        $show = Show::factory()->create();

        // Act
        $like = resolve(LikeTitle::class)->handle($user, $show);

        // Assert
        expect($like->likeable_type)->toBe('show');
        $this->assertDatabaseHas('likes', [
            'likeable_type' => 'show',
            'likeable_id' => $show->id,
        ]);
        $this->assertDatabaseMissing('likes', ['likeable_type' => Show::class]);
    });
});

describe('LikeTitle::handle() behavior defaults', function (): void {
    it('arrives with both acquire and notify enabled', function (): void {
        // Arrange
        $user = User::factory()->create();
        $movie = Movie::factory()->create();

        // Act
        $like = resolve(LikeTitle::class)->handle($user, $movie);

        // `enabled_at` carries an unfrozen clock value, so enabled is asserted as
        // set-vs-null rather than as an instant.
        // Assert
        $enabled = $like->behaviors
            ->filter(fn (LikeBehavior $behavior): bool => $behavior->enabled_at !== null)
            ->map(fn (LikeBehavior $behavior): Behavior => $behavior->behavior)
            ->values()
            ->all();
        expect($enabled)->toEqualCanonicalizing([Behavior::Acquire, Behavior::Notify]);
        $this->assertDatabaseCount('like_behaviors', 2);
        $this->assertDatabaseHas('like_behaviors', ['behavior' => 'acquire']);
        $this->assertDatabaseHas('like_behaviors', ['behavior' => 'notify']);
        $this->assertDatabaseMissing('like_behaviors', ['enabled_at' => null]);
    });
});
