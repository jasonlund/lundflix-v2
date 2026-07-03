<?php

declare(strict_types=1);

use App\Domains\Catalog\Models\Movie;
use App\Domains\Catalog\Models\Show;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('indexes a movie title and year from TMDB source-of-truth fields', function (): void {
    // Arrange
    $movie = Movie::factory()->withTmdb()->create([
        '_tmdb_title' => 'The Matrix',
        '_tmdb_release_date' => '1999-03-31',
    ]);

    // Act
    $array = $movie->toSearchableArray();

    // Assert
    expect($array['title'])->toBe('The Matrix');
    expect($array['year'])->toBe(1999);
});

it('keeps the IMDb ranking crosswalk fields when indexing a movie', function (): void {
    // Arrange
    $movie = Movie::factory()->withTmdb()->create([
        '_imdb_id' => 'tt0133093',
        '_imdb_num_votes' => 1_900_000,
        '_imdb_average_rating' => 8.7,
    ]);

    // Act
    $array = $movie->toSearchableArray();

    // Assert
    expect($array['imdb_id'])->toBe('tt0133093');
    expect($array['num_votes'])->toBe(1_900_000);
    expect($array['average_rating'])->toBe(8.7);
});

it('indexes a show title and year from TVDB source-of-truth fields', function (): void {
    // Arrange
    $show = Show::factory()->withTvdb()->create([
        '_tvdb_name' => 'Breaking Bad',
        '_tvdb_year' => '2008',
    ]);

    // Act
    $array = $show->toSearchableArray();

    // Assert
    expect($array['title'])->toBe('Breaking Bad');
    expect($array['year'])->toBe(2008);
});

it('keeps the IMDb ranking crosswalk fields when indexing a show', function (): void {
    // Arrange
    $show = Show::factory()->withTvdb()->create([
        '_imdb_id' => 'tt0903747',
        '_imdb_num_votes' => 2_100_000,
        '_imdb_average_rating' => 9.5,
    ]);

    // Act
    $array = $show->toSearchableArray();

    // Assert
    expect($array['imdb_id'])->toBe('tt0903747');
    expect($array['num_votes'])->toBe(2_100_000);
    expect($array['average_rating'])->toBe(9.5);
});
