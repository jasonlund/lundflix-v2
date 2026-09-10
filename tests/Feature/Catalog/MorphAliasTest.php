<?php

declare(strict_types=1);

use App\Domains\Catalog\Models\Episode;
use App\Domains\Catalog\Models\Media;
use App\Domains\Catalog\Models\Movie;
use App\Domains\Catalog\Models\Show;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;

/*
 * The morph map had no test coverage at all, so every case here except the
 * episode alias characterizes behavior that already shipped — the movie and show
 * entries and the alias written to a polymorphic column were registered long
 * before this file existed. Read them as a net cast under a chokepoint that had
 * none, not as tests that ever drove a change; only the episode alias did.
 */

describe('morph map alias resolution', function (): void {
    it('resolves the movie alias to the movie model', function (): void {
        // Arrange
        // the morph map is registered when the app boots, so there is no state to set up

        // Act
        $model = Relation::getMorphedModel('movie');

        // Assert
        expect($model)->toBe(Movie::class);
    });

    it('resolves the show alias to the show model', function (): void {
        // Arrange
        // the morph map is registered when the app boots, so there is no state to set up

        // Act
        $model = Relation::getMorphedModel('show');

        // Assert
        expect($model)->toBe(Show::class);
    });

    it('resolves the episode alias to the episode model', function (): void {
        // Arrange
        // the morph map is registered when the app boots, so there is no state to set up

        // Act
        $model = Relation::getMorphedModel('episode');

        // Assert
        expect($model)->toBe(Episode::class);
    });

    it('resolves the movie model back to the movie alias', function (): void {
        // Arrange
        // the morph map is registered when the app boots, so there is no state to set up

        // Act
        $alias = Relation::getMorphAlias(Movie::class);

        // Assert
        expect($alias)->toBe('movie');
    });
});

describe('morph alias persistence', function (): void {
    it('writes the alias rather than the class name to a polymorphic column', function (): void {
        // Arrange
        $movie = Movie::factory()->create();

        // Act
        $media = Media::factory()->for($movie, 'mediable')->create();

        // Assert
        // read the raw column through the query builder: going through the model
        // would resolve the alias back to a class and hide what was stored
        $stored = DB::table('media')->where('id', $media->id)->value('mediable_type');

        expect($stored)->toBe('movie')
            ->and($stored)->not->toBe(Movie::class);
    });
});
