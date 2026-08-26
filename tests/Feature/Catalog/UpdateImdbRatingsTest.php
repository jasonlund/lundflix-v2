<?php

declare(strict_types=1);

use App\Domains\Catalog\Actions\UpdateImdbRatings;
use App\Domains\Catalog\Models\Movie;
use App\Domains\Catalog\Models\Show;

it('updates the ratings of an existing movie', function (): void {
    // Arrange
    $movie = Movie::factory()->create(['_imdb_numVotes' => 100, '_imdb_averageRating' => 1.0]);

    // Act
    $result = resolve(UpdateImdbRatings::class)->handle([
        $movie->_imdb_id => ['numVotes' => 2252453, 'averageRating' => 8.7],
    ]);

    // Assert
    $fresh = Movie::query()->find($movie->id);
    expect($fresh->_imdb_numVotes)->toBe(2252453)
        ->and($fresh->_imdb_averageRating)->toBe(8.7)
        ->and($result)->toBe(['movies' => 1, 'shows' => 0]);
});

it('updates the ratings of an existing show', function (): void {
    // Arrange
    $show = Show::factory()->create(['_imdb_numVotes' => 100, '_imdb_averageRating' => 1.0]);

    // Act
    $result = resolve(UpdateImdbRatings::class)->handle([
        $show->_imdb_id => ['numVotes' => 987654, 'averageRating' => 9.2],
    ]);

    // Assert
    $fresh = Show::query()->find($show->id);
    expect($fresh->_imdb_numVotes)->toBe(987654)
        ->and($fresh->_imdb_averageRating)->toBe(9.2)
        ->and($result)->toBe(['movies' => 0, 'shows' => 1]);
});

it('skips an imdb_id with no matching title', function (): void {
    // Arrange
    $movie = Movie::factory()->create(['_imdb_numVotes' => 100, '_imdb_averageRating' => 1.0]);

    // Act
    $result = resolve(UpdateImdbRatings::class)->handle([
        $movie->_imdb_id => ['numVotes' => 2252453, 'averageRating' => 8.7],
        'tt9999999' => ['numVotes' => 50, 'averageRating' => 3.3],
    ]);

    // Assert
    expect(Movie::query()->count())->toBe(1)
        ->and(Show::query()->count())->toBe(0)
        ->and(Movie::query()->where('_imdb_id', 'tt9999999')->exists())->toBeFalse()
        ->and($result)->toBe(['movies' => 1, 'shows' => 0]);
});

it('updates a mixed batch across both tables in one call', function (): void {
    // Arrange
    $movie = Movie::factory()->create(['_imdb_numVotes' => 100, '_imdb_averageRating' => 1.0]);
    $show = Show::factory()->create(['_imdb_numVotes' => 200, '_imdb_averageRating' => 2.0]);

    // Act
    $result = resolve(UpdateImdbRatings::class)->handle([
        $movie->_imdb_id => ['numVotes' => 2252453, 'averageRating' => 8.7],
        $show->_imdb_id => ['numVotes' => 987654, 'averageRating' => 9.2],
    ]);

    // Assert
    $freshMovie = Movie::query()->find($movie->id);
    $freshShow = Show::query()->find($show->id);
    expect($freshMovie->_imdb_numVotes)->toBe(2252453)
        ->and($freshMovie->_imdb_averageRating)->toBe(8.7)
        ->and($freshShow->_imdb_numVotes)->toBe(987654)
        ->and($freshShow->_imdb_averageRating)->toBe(9.2)
        ->and($result)->toBe(['movies' => 1, 'shows' => 1]);
});

it('passes nothing to the search engine while still applying the ratings', function (): void {
    // Arrange
    $movie = Movie::factory()->create(['_imdb_numVotes' => 100, '_imdb_averageRating' => 1.0]);
    $show = Show::factory()->create(['_imdb_numVotes' => 200, '_imdb_averageRating' => 2.0]);
    // Registered last so the factory saves' own create-time syncs aren't captured
    // — otherwise every row looks reindexed and nothing can ever look quiet.
    $capturedChunks = spyOnScoutEngine();

    // Act
    $result = resolve(UpdateImdbRatings::class)->handle([
        $movie->_imdb_id => ['numVotes' => 2252453, 'averageRating' => 8.7],
        $show->_imdb_id => ['numVotes' => 987654, 'averageRating' => 9.2],
    ]);

    // Assert
    expect(reindexedIds($capturedChunks()))->toBe([])
        ->and(Movie::query()->find($movie->id)->_imdb_numVotes)->toBe(2252453)
        ->and(Show::query()->find($show->id)->_imdb_numVotes)->toBe(987654)
        ->and($result)->toBe(['movies' => 1, 'shows' => 1]);
});
