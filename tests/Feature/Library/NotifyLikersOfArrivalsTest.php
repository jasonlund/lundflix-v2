<?php

declare(strict_types=1);

use App\Domains\Catalog\Data\UnitRef;
use App\Domains\Catalog\Enums\UnitKind;
use App\Domains\Catalog\Models\Episode;
use App\Domains\Catalog\Models\Movie;
use App\Domains\Catalog\Models\Show;
use App\Domains\Identity\Models\User;
use App\Domains\Library\Actions\LikeTitle;
use App\Domains\Library\Actions\UnlikeTitle;
use App\Domains\Library\Enums\Behavior;
use App\Domains\Library\Models\Like;
use App\Domains\Library\Models\LikeBehavior;
use App\Domains\Library\Notifications\LikedUnitsArrived;
use App\Domains\PlexLibrary\Data\ArrivedTitle;
use App\Domains\PlexLibrary\Events\UnitsArrived;

/*
 * What the Library does when PlexLibrary publishes UnitsArrived: tell each person whose
 * like carries Notify, once per unit ever, in one in-app notification per publication.
 *
 * Every Act dispatches through the real event dispatcher — no Event::fake, no
 * Notification::fake — so a passing test also proves the listener is registered, and
 * the outcome is read back from the real `notifications` and `like_notifications` rows.
 */

/**
 * The published title for a movie, carrying the movie as its one unit.
 */
function arrivedMovieTitle(Movie $movie, string $name): ArrivedTitle
{
    return new ArrivedTitle('movie', $movie->id, $name, [new UnitRef(UnitKind::Movie, $movie->id)]);
}

describe('UnitsArrived liker notification', function (): void {
    it('tells a user whose liked movie arrives in one unread notification naming it', function (): void {
        // Arrange
        $user = User::factory()->create();
        $movie = Movie::factory()->create();
        notifyingLikeOf($user, $movie);
        $titles = [arrivedMovieTitle($movie, 'Dune')];

        // Act
        event(new UnitsArrived($titles));

        // Assert
        $notifications = $user->unreadNotifications()->get();
        expect($notifications)->toHaveCount(1)
            ->and($notifications->first()?->type)->toBe(LikedUnitsArrived::class)
            ->and($notifications->first()?->data)->toBe(['lines' => ['Dune']]);
        $this->assertDatabaseHas('like_notifications', [
            'user_id' => $user->id,
            'unit_kind' => 'movie',
            'unit_id' => $movie->id,
        ]);
    });

    it("counts a liked show's arrived episodes on one line", function (int $episodeCount, string $line): void {
        // Arrange
        $user = User::factory()->create();
        $show = Show::factory()->create();
        notifyingLikeOf($user, $show);
        $units = Episode::factory()->count($episodeCount)->create(['show_id' => $show->id])
            ->map(fn (Episode $episode): UnitRef => new UnitRef(UnitKind::Episode, $episode->id))
            ->values()
            ->all();
        $titles = [new ArrivedTitle('show', $show->id, 'Severance', $units)];

        // Act
        event(new UnitsArrived($titles));

        // Assert
        $notifications = $user->unreadNotifications()->get();
        expect($notifications)->toHaveCount(1)
            ->and($notifications->first()?->data)->toBe(['lines' => [$line]]);
    })->with([
        'one episode' => [1, 'Severance: 1 new episode'],
        'two episodes' => [2, 'Severance: 2 new episodes'],
    ]);

    it('gives a user whose liked titles both arrive one notification carrying a line per title', function (): void {
        // Arrange
        $user = User::factory()->create();
        $movie = Movie::factory()->create();
        $show = Show::factory()->create();
        $episode = Episode::factory()->create(['show_id' => $show->id]);
        notifyingLikeOf($user, $movie);
        notifyingLikeOf($user, $show);
        $titles = [
            arrivedMovieTitle($movie, 'Dune'),
            new ArrivedTitle('show', $show->id, 'Severance', [new UnitRef(UnitKind::Episode, $episode->id)]),
        ];

        // Act
        event(new UnitsArrived($titles));

        // Assert
        $notifications = $user->unreadNotifications()->get();
        expect($notifications)->toHaveCount(1)
            ->and($notifications->first()?->data['lines'] ?? null)
            ->toEqualCanonicalizing(['Dune', 'Severance: 1 new episode']);
    });
});

describe('UnitsArrived liker recipients', function (): void {
    it('tells each of two users liking the same title', function (): void {
        // Arrange
        $first = User::factory()->create();
        $second = User::factory()->create();
        $movie = Movie::factory()->create();
        notifyingLikeOf($first, $movie);
        notifyingLikeOf($second, $movie);
        $titles = [arrivedMovieTitle($movie, 'Dune')];

        // Act
        event(new UnitsArrived($titles));

        // Assert
        foreach ([$first, $second] as $user) {
            $notifications = $user->unreadNotifications()->get();
            expect($notifications)->toHaveCount(1)
                ->and($notifications->first()?->data)->toBe(['lines' => ['Dune']]);
        }
    });

    it('tells nobody whose like has Notify switched off', function (): void {
        // Arrange
        // Acquire stays on, so only the Notify behavior can be what keeps the user out.
        $user = User::factory()->create();
        $movie = Movie::factory()->create();
        $like = Like::factory()->for($user)->for($movie, 'likeable')->create();
        LikeBehavior::factory()->for($like)->create(['behavior' => Behavior::Acquire]);
        LikeBehavior::factory()->for($like)->disabled()->create(['behavior' => Behavior::Notify]);
        $titles = [arrivedMovieTitle($movie, 'Dune')];

        // Act
        event(new UnitsArrived($titles));

        // Assert
        expect($user->notifications()->count())->toBe(0);
        $this->assertDatabaseCount('like_notifications', 0);
    });
});

describe('UnitsArrived liker once-ever', function (): void {
    it('tells a user nothing new when the same arrival repeats after they unlike and re-like the title', function (): void {
        // Arrange
        $user = User::factory()->create();
        $movie = Movie::factory()->create();
        notifyingLikeOf($user, $movie);
        $titles = [arrivedMovieTitle($movie, 'Dune')];
        event(new UnitsArrived($titles));
        resolve(UnlikeTitle::class)->handle($user, $movie);
        resolve(LikeTitle::class)->handle($user, $movie);

        // Act
        event(new UnitsArrived($titles));

        // Assert
        expect($user->notifications()->count())->toBe(1);
        $this->assertDatabaseCount('like_notifications', 1);
    });
});
