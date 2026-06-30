<?php

declare(strict_types=1);

use App\Domains\Catalog\Actions\UpdateImdbRatings;
use App\Domains\Catalog\Models\Movie;
use App\Domains\Catalog\Models\Show;

it('updates the ratings of an existing movie', function (): void {
    // Arrange
    $movie = Movie::factory()->create(['_imdb_num_votes' => 100, '_imdb_average_rating' => 1.0]);

    // Act
    $result = resolve(UpdateImdbRatings::class)->handle([
        $movie->_imdb_id => ['num_votes' => 2252453, 'average_rating' => 8.7],
    ]);

    // Assert
    $fresh = Movie::query()->find($movie->id);
    expect($fresh->_imdb_num_votes)->toBe(2252453)
        ->and($fresh->_imdb_average_rating)->toBe(8.7)
        ->and($result)->toBe(['movies' => 1, 'shows' => 0]);
});

it('updates the ratings of an existing show', function (): void {
    // Arrange
    $show = Show::factory()->create(['_imdb_num_votes' => 100, '_imdb_average_rating' => 1.0]);

    // Act
    $result = resolve(UpdateImdbRatings::class)->handle([
        $show->_imdb_id => ['num_votes' => 987654, 'average_rating' => 9.2],
    ]);

    // Assert
    $fresh = Show::query()->find($show->id);
    expect($fresh->_imdb_num_votes)->toBe(987654)
        ->and($fresh->_imdb_average_rating)->toBe(9.2)
        ->and($result)->toBe(['movies' => 0, 'shows' => 1]);
});

it('skips an imdb_id with no matching title', function (): void {
    // Arrange
    $movie = Movie::factory()->create(['_imdb_num_votes' => 100, '_imdb_average_rating' => 1.0]);

    // Act
    $result = resolve(UpdateImdbRatings::class)->handle([
        $movie->_imdb_id => ['num_votes' => 2252453, 'average_rating' => 8.7],
        'tt9999999' => ['num_votes' => 50, 'average_rating' => 3.3],
    ]);

    // Assert
    expect(Movie::query()->count())->toBe(1)
        ->and(Show::query()->count())->toBe(0)
        ->and(Movie::query()->where('_imdb_id', 'tt9999999')->exists())->toBeFalse()
        ->and($result)->toBe(['movies' => 1, 'shows' => 0]);
});

it('appends CASE bindings to pre-existing join bindings instead of replacing them', function (): void {
    // A query that already carries a parameterised join, so the 'join' binding
    // slot is non-empty before the action assigns the CASE bindings. The old code
    // did `bindings['join'] = array_merge($case...)`, dropping the join binding
    // entirely; a future join/global-scope on Movie would then have its binding
    // silently swallowed. The fix must keep the existing join binding.
    // Arrange
    $movie = Movie::factory()->create(['_imdb_num_votes' => 100, '_imdb_average_rating' => 1.0]);
    $scopedQuery = Movie::query()->joinSub(
        DB::table('movies')->select('id as scoped_id')->where('_imdb_num_votes', '>', -98765),
        'scoped',
        'movies.id',
        '=',
        'scoped.scoped_id',
    );
    DB::enableQueryLog();

    // Act
    (new ReflectionMethod(UpdateImdbRatings::class, 'updateTable'))->invoke(
        resolve(UpdateImdbRatings::class),
        $scopedQuery,
        [$movie->_imdb_id => ['num_votes' => 2252453, 'average_rating' => 8.7]],
    );

    // The join's own binding (-98765) survives in the executed update.
    // Assert
    $updateLog = collect(DB::getQueryLog())->firstWhere(fn (array $entry): bool => str_starts_with((string) $entry['query'], 'update'));
    expect($updateLog['bindings'])->toContain(-98765);
});

it('updates a mixed batch across both tables in one call', function (): void {
    // Arrange
    $movie = Movie::factory()->create(['_imdb_num_votes' => 100, '_imdb_average_rating' => 1.0]);
    $show = Show::factory()->create(['_imdb_num_votes' => 200, '_imdb_average_rating' => 2.0]);

    // Act
    $result = resolve(UpdateImdbRatings::class)->handle([
        $movie->_imdb_id => ['num_votes' => 2252453, 'average_rating' => 8.7],
        $show->_imdb_id => ['num_votes' => 987654, 'average_rating' => 9.2],
    ]);

    // Assert
    $freshMovie = Movie::query()->find($movie->id);
    $freshShow = Show::query()->find($show->id);
    expect($freshMovie->_imdb_num_votes)->toBe(2252453)
        ->and($freshMovie->_imdb_average_rating)->toBe(8.7)
        ->and($freshShow->_imdb_num_votes)->toBe(987654)
        ->and($freshShow->_imdb_average_rating)->toBe(9.2)
        ->and($result)->toBe(['movies' => 1, 'shows' => 1]);
});
