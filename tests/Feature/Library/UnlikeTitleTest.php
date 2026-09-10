<?php

declare(strict_types=1);

use App\Domains\Catalog\Models\Movie;
use App\Domains\Identity\Models\User;
use App\Domains\Library\Actions\UnlikeTitle;
use App\Domains\Library\Enums\Behavior;
use App\Domains\Library\Models\Like;
use App\Domains\Library\Models\LikeBehavior;

describe('UnlikeTitle::handle() removal', function (): void {
    it('removes the relationship the user unliked', function (): void {
        // A second user's like of the same movie is arranged so a blanket wipe
        // cannot pass for a scoped removal.
        // Arrange
        $user = User::factory()->create();
        $movie = Movie::factory()->create();
        $like = Like::factory()->for($user)->for($movie, 'likeable')->create();
        $otherLike = Like::factory()->for($movie, 'likeable')->create();

        // Act
        resolve(UnlikeTitle::class)->handle($user, $movie);

        // Assert
        $this->assertDatabaseMissing('likes', ['id' => $like->id]);
        $this->assertDatabaseCount('likes', 1);
        $this->assertDatabaseHas('likes', ['id' => $otherLike->id]);
    });

    it('removes the behaviors that hung off the relationship', function (): void {
        // Arrange
        $user = User::factory()->create();
        $movie = Movie::factory()->create();
        $like = Like::factory()->for($user)->for($movie, 'likeable')->create();
        LikeBehavior::factory()->for($like)->create(['behavior' => Behavior::Acquire]);
        LikeBehavior::factory()->for($like)->disabled()->create(['behavior' => Behavior::Notify]);
        $otherBehavior = LikeBehavior::factory()->for(Like::factory()->for($movie, 'likeable'))->create();

        // Act
        resolve(UnlikeTitle::class)->handle($user, $movie);

        // Assert
        $this->assertDatabaseMissing('like_behaviors', ['like_id' => $like->id]);
        $this->assertDatabaseCount('like_behaviors', 1);
        $this->assertDatabaseHas('like_behaviors', ['id' => $otherBehavior->id]);
    });
});

describe('likes cascade on user deletion', function (): void {
    it('takes a departing user\'s likes and their behaviors with them', function (): void {
        // Arrange
        $user = User::factory()->create();
        $like = Like::factory()->for($user)->for(Movie::factory(), 'likeable')->create();
        LikeBehavior::factory()->for($like)->create(['behavior' => Behavior::Acquire]);
        LikeBehavior::factory()->for($like)->create(['behavior' => Behavior::Notify]);

        // Act
        $user->delete();

        // Assert
        $this->assertDatabaseCount('likes', 0);
        $this->assertDatabaseCount('like_behaviors', 0);
    });
});
