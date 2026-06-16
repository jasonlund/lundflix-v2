<?php

use App\Domains\Catalog\Actions\UpdateImdbRatings;
use App\Domains\Catalog\Models\Movie;
use App\Domains\Catalog\Models\Show;

it('updates the ratings of an existing movie', function () {
    // Arrange
    $movie = Movie::factory()->create(['num_votes' => 100, 'average_rating' => 1.0]);

    // Act
    $result = app(UpdateImdbRatings::class)->handle([
        $movie->imdb_id => ['num_votes' => 2252453, 'average_rating' => 8.7],
    ]);

    // Assert
    $fresh = Movie::query()->find($movie->id);
    expect($fresh->num_votes)->toBe(2252453)
        ->and($fresh->average_rating)->toBe(8.7)
        ->and($result)->toBe(['movies' => 1, 'shows' => 0]);
});

it('updates the ratings of an existing show', function () {
    // Arrange
    $show = Show::factory()->create(['num_votes' => 100, 'average_rating' => 1.0]);

    // Act
    $result = app(UpdateImdbRatings::class)->handle([
        $show->imdb_id => ['num_votes' => 987654, 'average_rating' => 9.2],
    ]);

    // Assert
    $fresh = Show::query()->find($show->id);
    expect($fresh->num_votes)->toBe(987654)
        ->and($fresh->average_rating)->toBe(9.2)
        ->and($result)->toBe(['movies' => 0, 'shows' => 1]);
});

it('skips an imdb_id with no matching title', function () {
    // Arrange
    $movie = Movie::factory()->create(['num_votes' => 100, 'average_rating' => 1.0]);

    // Act
    $result = app(UpdateImdbRatings::class)->handle([
        $movie->imdb_id => ['num_votes' => 2252453, 'average_rating' => 8.7],
        'tt9999999' => ['num_votes' => 50, 'average_rating' => 3.3],
    ]);

    // Assert
    expect(Movie::query()->count())->toBe(1)
        ->and(Show::query()->count())->toBe(0)
        ->and(Movie::query()->where('imdb_id', 'tt9999999')->exists())->toBeFalse()
        ->and($result)->toBe(['movies' => 1, 'shows' => 0]);
});

it('updates a mixed batch across both tables in one call', function () {
    // Arrange
    $movie = Movie::factory()->create(['num_votes' => 100, 'average_rating' => 1.0]);
    $show = Show::factory()->create(['num_votes' => 200, 'average_rating' => 2.0]);

    // Act
    $result = app(UpdateImdbRatings::class)->handle([
        $movie->imdb_id => ['num_votes' => 2252453, 'average_rating' => 8.7],
        $show->imdb_id => ['num_votes' => 987654, 'average_rating' => 9.2],
    ]);

    // Assert
    $freshMovie = Movie::query()->find($movie->id);
    $freshShow = Show::query()->find($show->id);
    expect($freshMovie->num_votes)->toBe(2252453)
        ->and($freshMovie->average_rating)->toBe(8.7)
        ->and($freshShow->num_votes)->toBe(987654)
        ->and($freshShow->average_rating)->toBe(9.2)
        ->and($result)->toBe(['movies' => 1, 'shows' => 1]);
});
